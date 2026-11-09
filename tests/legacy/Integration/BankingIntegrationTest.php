<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankAdapter;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;

/** Boleto da fatura até a baixa pelo retorno, com idempotência, divergências e escopo (ADR 0035). */
final class BankingIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private BankCharges $charges;
    private BankReturns $returns;
    private int $bank;
    /** @var list<int> contas a receber geradas pela fatura */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->charges = new BankCharges($this->pdo);
        $this->returns = new BankReturns($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach (['101', '102'] as $number) {
            $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento']);
        }
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-11', 'due_on' => '2026-11-10'])['id']);
        $this->entries = array_column($invoice['items'], 'entry_id');
    }

    protected function tearDown(): void
    {
        BankAdapters::override('sandbox', null);
        parent::tearDown();
    }

    public function testIssueIsIdempotentAndReturnSettlesOnce(): void
    {
        $first = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame(['registered', '0000000001', 50000], [$first['status'], $first['our_number'], $first['amount_cents']]);
        self::assertSame($first['id'], $this->charges->issue($this->admin, $this->entries[0], $this->bank)['id'], 'Pedir de novo devolve o mesmo título.');
        $second = $this->charges->issue($this->admin, $this->entries[1], $this->bank);
        self::assertSame('0000000002', $second['our_number']);

        $content = SandboxBankAdapter::buildReturn([
            ['event_key' => 'PAG-1', 'kind' => 'paid', 'our_number' => '0000000001', 'amount_cents' => 50000, 'occurred_on' => '2026-11-09'],
            ['event_key' => 'PAG-2', 'kind' => 'paid', 'our_number' => '0000000002', 'amount_cents' => 45000, 'occurred_on' => '2026-11-09'],
            ['event_key' => 'PAG-3', 'kind' => 'paid', 'our_number' => '0000009999', 'amount_cents' => 100, 'occurred_on' => '2026-11-09'],
        ]);
        $result = $this->returns->process($this->admin, $this->bank, 'retorno-1.txt', $content);
        self::assertFalse($result['duplicate_file']);
        self::assertSame(['applied', 'divergence', 'unknown_charge'], array_column($result['events'], 'outcome'));
        self::assertSame([1, 2], [$result['file']['applied_count'], $result['file']['divergence_count']]);

        $entry = $this->service->find($this->admin, 'entries', $this->entries[0]);
        self::assertSame(['2026-11-09', $this->bank], [$entry['paid_on'], $entry['bank_account_id']], 'Baixa com a data e a conta do retorno.');
        self::assertSame('paid', $this->charges->find($this->admin, $first['id'])['status']);
        self::assertNull($this->service->find($this->admin, 'entries', $this->entries[1])['paid_on'], 'Pagamento parcial não dá baixa.');
        self::assertSame('registered', $this->charges->find($this->admin, $second['id'])['status']);

        // Mesmo arquivo de novo: nada muda. Mesmo evento em outro arquivo: ignorado.
        self::assertTrue($this->returns->process($this->admin, $this->bank, 'retorno-1-copia.txt', $content)['duplicate_file']);
        $again = $this->returns->process($this->admin, $this->bank, 'retorno-2.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'PAG-1', 'kind' => 'paid', 'our_number' => '0000000001', 'amount_cents' => 50000, 'occurred_on' => '2026-11-09'],
        ]));
        self::assertSame([], $again['events']);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_events WHERE event_key = 'PAG-1'")->fetchColumn());

        $queue = $this->returns->divergences($this->admin, $this->alpha);
        self::assertCount(2, $queue);
        $this->returns->resolve($this->admin, $queue[0]['id'], 'Conferido com o extrato do banco');
        self::assertCount(1, $this->returns->divergences($this->admin));

        $this->expectException(DomainException::class);
        $this->charges->cancel($this->admin, $first['id']);   // pago não cancela
    }

    public function testPendingUntilConfirmedAndCancel(): void
    {
        BankAdapters::override('sandbox', new class extends \stdClass implements BankAdapter {
            public function name(): string { return 'sandbox'; }
            public function capabilities(): array { return ['register', 'query', 'cancel', 'return_file']; }
            public function registerCharge(array $charge): array { throw new \RuntimeException('timeout'); }
            public function getCharge(string $ourNumber, ?string $externalId): array { return (new SandboxBankAdapter())->getCharge($ourNumber, $externalId); }
            public function cancelCharge(string $ourNumber, ?string $externalId): array { return ['status' => 'cancelled', 'error' => null]; }
            public function parseReturn(string $content): array { return (new SandboxBankAdapter())->parseReturn($content); }
        });
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame('pending', $charge['status'], 'Timeout não vira registrado.');
        self::assertNotNull($charge['last_error']);
        self::assertSame('registered', $this->charges->refresh($this->admin, $charge['id'])['status']);

        $cancelled = $this->charges->cancel($this->admin, $charge['id']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertNotSame($charge['id'], $this->charges->issue($this->admin, $this->entries[0], $this->bank)['id'], 'Cancelado libera novo título.');
    }

    public function testClosedCompetenceGoesToReviewAndScope(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        (new MonthClosing($this->pdo))->close($this->admin, $this->alpha, '2026-11');
        $result = $this->returns->process($this->admin, $this->bank, 'r.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'PAG-9', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => 50000, 'occurred_on' => '2026-12-02'],
        ]));
        self::assertSame('divergence', $result['events'][0]['outcome']);
        self::assertStringContainsString('fechada', (string)$result['events'][0]['note']);
        self::assertNull($this->service->find($this->admin, 'entries', $this->entries[0])['paid_on']);

        $outsider = $this->user([$this->beta]);
        self::assertSame(0, $this->charges->list($outsider)['total']);
        self::assertSame([], $this->returns->divergences($outsider));
        $this->expectException(RegistryNotFound::class);
        $this->returns->process($outsider, $this->bank, 'x.txt', "SANDBOX-RETORNO;v1\nTOTAL;0;0\n");
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
