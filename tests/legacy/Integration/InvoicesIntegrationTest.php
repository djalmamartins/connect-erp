<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Faturas: preparar, ajustar, emitir em contas a receber, cancelar, idempotência e escopo (ADR 0033). */
final class InvoicesIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private Invoices $invoices;
    private User $admin;
    private int $fee;
    private int $type;
    /** @var list<int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->invoices = new Invoices($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial'])['id'];
        $this->type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $this->fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach ([['101', '0,250000'], ['102', '0,250000'], ['201', '0,500000']] as [$number, $fraction]) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento', 'ideal_fraction' => $fraction])['id'];
        }
    }

    public function testPrepareAdjustIssueCreatesReceivablesOnce(): void
    {
        $draft = $this->invoices->prepare($this->admin, $this->input());
        self::assertSame(['draft', 3, 150000], [$draft['status'], $draft['units_count'], $draft['amount_cents']], 'Valor padrão do tipo por unidade.');

        $adjusted = $this->invoices->setItemAmount($this->admin, $draft['id'], $draft['items'][0]['id'], '650,00');
        self::assertSame(165000, $adjusted['amount_cents']);

        try {
            $this->invoices->prepare($this->admin, $this->input());
            self::fail('Preparou duas faturas ativas do mesmo tipo e competência.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        $issued = $this->invoices->issue($this->admin, $draft['id']);
        self::assertSame('issued', $issued['status']);
        self::assertCount(3, array_filter(array_column($issued['items'], 'entry_id')));
        $receivables = $this->service->list($this->admin, 'receivables', ['condominium_id' => $this->alpha]);
        self::assertSame(3, $receivables['total']);
        self::assertEqualsCanonicalizing($this->units, array_map('intval', array_column($receivables['data'], 'unit_id')));
        self::assertSame(165000, array_sum(array_map('intval', array_column($receivables['data'], 'amount_cents'))));

        $this->expectException(DomainException::class);
        $this->invoices->issue($this->admin, $draft['id']);
    }

    public function testFractionMethodAndValidation(): void
    {
        $invoice = $this->invoices->prepare($this->admin, $this->input(['method' => 'fraction', 'amount_cents' => '1.000,00']));
        self::assertSame([25000, 25000, 50000], array_column($invoice['items'], 'amount_cents'));

        foreach ([['competence' => '2026-13'], ['due_on' => '2026-02-30'], ['invoice_type_id' => 999999], ['condominium_id' => $this->beta, 'competence' => '2026-12']] as $bad) {
            try {
                $this->invoices->prepare($this->admin, $this->input($bad + ['competence' => '2026-12']));
                self::fail('Aceitou entrada inválida: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);   // Beta não tem unidades: também é 422
            }
        }
    }

    public function testGeneratedEntryIsLockedAndCancelRules(): void
    {
        $invoice = $this->invoices->issue($this->admin, $this->invoices->prepare($this->admin, $this->input())['id']);
        $entryId = $invoice['items'][0]['entry_id'];
        $entry = $this->service->find($this->admin, 'entries', $entryId);

        try {
            $this->service->save($this->admin, 'entries', ['amount_cents' => '1,00'] + $this->editable($entry), $entryId);
            self::fail('Alterou o valor de um lançamento gerado por fatura.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('amount_cents', $exception->errors);
        }
        try {
            $this->service->delete($this->admin, 'entries', $entryId);
            self::fail('Excluiu lançamento gerado por fatura.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        try {
            $this->service->delete($this->admin, 'units', $this->units[0]);
            self::fail('Excluiu unidade com lançamento.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }

        // Baixa continua livre; com uma conta paga, a fatura não cancela.
        $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->service->save($this->admin, 'entries', ['paid_on' => '2026-11-10', 'bank_account_id' => $bank] + $this->editable($entry), $entryId);
        try {
            $this->invoices->cancel($this->admin, $invoice['id'], 'Valor errado na assembleia');
            self::fail('Cancelou fatura com conta paga.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }
        $this->service->save($this->admin, 'entries', ['paid_on' => '', 'bank_account_id' => $bank] + $this->editable($entry), $entryId);

        try {
            $this->invoices->cancel($this->admin, $invoice['id'], 'x');
            self::fail('Cancelou sem motivo.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $cancelled = $this->invoices->cancel($this->admin, $invoice['id'], 'Valor errado na assembleia');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(['inactive'], array_values(array_unique(array_column($cancelled['items'], 'entry_status'))));
        self::assertSame(0, $this->service->list($this->admin, 'receivables', ['condominium_id' => $this->alpha, 'status' => 'active'])['total']);
        self::assertSame('draft', $this->invoices->prepare($this->admin, $this->input())['status'], 'Cancelada libera nova fatura.');
        self::assertGreaterThanOrEqual(4, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_invoices'")->fetchColumn());
    }

    public function testClosedCompetenceAndScope(): void
    {
        $draft = $this->invoices->prepare($this->admin, $this->input());
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-11');
        try {
            $this->invoices->issue($this->admin, $draft['id']);
            self::fail('Emitiu em competência fechada.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('competence', $exception->errors);
        }
        self::assertSame('draft', $this->invoices->find($this->admin, $draft['id'])['status'], 'Emissão é tudo ou nada.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE invoice_item_id IS NOT NULL')->fetchColumn());

        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->invoices->list($outsider)['total']);
        self::assertSame(1, $this->invoices->list($this->admin, $this->alpha)['total']);
        $this->expectException(RegistryNotFound::class);
        $this->invoices->find($outsider, $draft['id']);
    }

    private function input(array $override = []): array
    {
        return $override + ['condominium_id' => $this->alpha, 'invoice_type_id' => $this->type, 'competence' => '2026-11', 'due_on' => '2026-11-10'];
    }

    /** Campos do lançamento como o formulário reenvia (valores atuais). */
    private function editable(array $entry): array
    {
        $fields = ['condominium_id', 'nature', 'competence', 'description', 'chart_account_id', 'due_on', 'unit_id', 'document_number', 'status'];
        $values = array_intersect_key($entry, array_flip($fields));
        $values['amount_cents'] = number_format(((int)$entry['amount_cents']) / 100, 2, ',', '');
        return $values;
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
