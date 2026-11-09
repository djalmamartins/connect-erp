<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Inadimplência por unidade, acordo com parcelas, travas, cancelamento e escopo (ADR 0039). */
final class AgreementsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private Agreements $agreements;
    private int $fee;
    private int $agreementAccount;
    /** @var array<string, int> unidade => id */
    private array $units = [];
    /** @var array<string, list<int>> unidade => contas a receber */
    private array $debts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->agreements = new Agreements($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->agreementAccount = $this->service->save($this->admin, 'chart', ['code' => '1.02', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Acordos'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $this->fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach (['101', '102'] as $number) {
            $this->units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $invoices = new Invoices($this->pdo);
        foreach (['2026-08', '2026-09'] as $competence) {
            $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type,
                'competence' => $competence, 'due_on' => $competence . '-10'])['id']);
            foreach ($invoice['items'] as $item) {
                $this->debts[$item['unit_label']][] = $item['entry_id'];
            }
        }
    }

    public function testDelinquencyGroupsOverdueByUnit(): void
    {
        $report = $this->agreements->delinquency($this->admin, $this->alpha, '2026-11-09');
        self::assertSame(2, $report['totals']['units']);
        self::assertSame(4, $report['totals']['entries']);
        self::assertSame(200000, $report['totals']['amount_cents']);
        // 10/08 → 09/11 = 91 dias (90+); 10/09 → 09/11 = 60 dias (31-60).
        self::assertSame(['1-30' => 0, '31-60' => 100000, '61-90' => 0, '90+' => 100000], $report['totals']['buckets']);
        self::assertSame(91, $report['units'][0]['max_days_late']);
        self::assertSame(0, $this->agreements->delinquency($this->admin, $this->alpha, '2026-08-10')['totals']['entries'], 'Vence hoje ainda não está vencida.');
    }

    public function testAgreementReplacesDebtsWithInstallments(): void
    {
        $agreement = $this->create('101', ['fees_cents' => '50,00', 'discount_cents' => '20,00']);
        self::assertSame([100000, 5000, 2000, 103000, 3], [$agreement['original_cents'], $agreement['fees_cents'], $agreement['discount_cents'], $agreement['total_cents'], $agreement['installments']]);
        self::assertCount(2, $agreement['originals']);
        self::assertSame(['inactive'], array_values(array_unique(array_column($agreement['originals'], 'status'))));
        self::assertSame([34334, 34333, 34333], array_column($agreement['parcels'], 'amount_cents'));
        self::assertSame(['2026-12-10', '2027-01-10', '2027-02-10'], array_column($agreement['parcels'], 'due_on'));

        $report = $this->agreements->delinquency($this->admin, $this->alpha, '2026-11-09');
        self::assertSame(['102'], array_column($report['units'], 'unit_label'), 'A 101 saiu da inadimplência.');

        // Parcela e conta original ficam travadas no cadastro; a baixa continua livre.
        $parcel = $agreement['parcels'][0]['entry_id'];
        try {
            $this->service->delete($this->admin, 'entries', $parcel);
            self::fail('Excluiu parcela de acordo.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        try {
            $this->create('101');
            self::fail('Renegociou conta que já está em acordo.');
        } catch (InvalidArgumentException | DomainException) {
            self::addToAssertionCount(1);
        }

        $cancelled = $this->agreements->cancel($this->admin, $agreement['id'], 'Proprietário desistiu do acordo');
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame(['active'], array_values(array_unique(array_column($cancelled['originals'], 'status'))), 'Contas originais voltam a valer.');
        self::assertSame(['inactive'], array_values(array_unique(array_column($cancelled['parcels'], 'status'))));
        self::assertSame(2, $this->agreements->delinquency($this->admin, $this->alpha, '2026-11-09')['totals']['units']);
        self::assertSame('active', $this->create('101')['status'], 'Depois de cancelado, pode fazer outro acordo.');
    }

    public function testRefusalsAndScope(): void
    {
        // Conta de outra unidade, desconto maior que a dívida e competência fechada.
        foreach ([
            fn () => $this->agreements->create($this->admin, $this->input('101', ['entry_ids' => [$this->debts['102'][0]]])),
            fn () => $this->create('101', ['discount_cents' => '2.000,00']),
        ] as $call) {
            try {
                $call();
                self::fail('Aceitou acordo inválido.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-08');
        try {
            $this->create('101');
            self::fail('Renegociou conta de competência fechada.');
        } catch (DomainException $error) {
            self::assertStringContainsString('fechada', $error->getMessage());
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_agreements')->fetchColumn(), 'Recusa não deixa acordo pela metade.');

        // Boleto ativo bloqueia o acordo daquela conta.
        $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        (new BankCharges($this->pdo))->issue($this->admin, $this->debts['102'][1], $bank);
        try {
            $this->agreements->create($this->admin, $this->input('102', ['entry_ids' => [$this->debts['102'][1]]]));
            self::fail('Renegociou conta com boleto ativo.');
        } catch (DomainException) {
            self::addToAssertionCount(1);
        }

        // Conta de outro condomínio responde como inexistente (não revela que o id existe) e
        // não é travada: a consulta só olha contas do condomínio da unidade.
        $betaUnit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->beta, 'number' => '201', 'type' => 'apartamento'])['id'];
        $betaDebt = $this->service->save($this->admin, 'entries', ['condominium_id' => $this->beta, 'nature' => 'receita', 'chart_account_id' => $this->fee,
            'description' => 'Taxa Beta', 'amount_cents' => '100,00', 'competence' => '2026-09', 'due_on' => '2026-09-10', 'unit_id' => $betaUnit])['id'];
        foreach ([
            fn () => $this->agreements->create($this->admin, $this->input('101', ['entry_ids' => [$this->debts['101'][0], $betaDebt]])),
            fn () => $this->agreements->suggestion($this->admin, $this->units['101'], [$this->debts['101'][0], $betaDebt], '2026-11-09'),
        ] as $call) {
            try {
                $call();
                self::fail('Aceitou conta de outro condomínio.');
            } catch (InvalidArgumentException $error) {
                self::assertSame('Alguma conta escolhida não existe.', $error->getMessage());
            }
        }

        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->agreements->delinquency($outsider, null, '2026-11-09')['totals']['entries']);
        $this->expectException(RegistryNotFound::class);
        $this->agreements->create($outsider, $this->input('102', ['entry_ids' => [$this->debts['102'][0]]]));
    }

    private function create(string $unit, array $override = []): array
    {
        return $this->agreements->create($this->admin, $this->input($unit, $override));
    }

    private function input(string $unit, array $override = []): array
    {
        return $override + ['unit_id' => $this->units[$unit], 'entry_ids' => $this->debts[$unit], 'installments' => 3,
            'first_due_on' => '2026-12-10', 'chart_account_id' => $this->agreementAccount];
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
