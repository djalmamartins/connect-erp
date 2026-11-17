<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\EntryCancellation;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\PaymentLocks;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\RevenueLaunch;

/** Boleto único (ADR 0331): o rateio da Nova Receita com tipo de fatura entra no boleto da fatura da unidade. */
final class BankChargeBundleTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private int $fee;
    private int $type;
    private int $bank;
    /** @var array<string, int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->admin = (new User())->findById($id);
        $this->service = new RegistryService($this->pdo);
        $this->pdo->exec("INSERT INTO erp_chart_accounts (code, name, nature, kind) VALUES ('1', 'Receitas', 'receita', 'sintetica'), ('1.01', 'Taxa', 'receita', 'analitica')");
        $this->fee = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '1.01'")->fetchColumn();
        $this->type = (int)$this->service->save($this->admin, 'invoice_types', ['condominium_id' => $this->alpha, 'name' => 'Taxa condominial', 'chart_account_id' => $this->fee,
            'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach (['101', '102'] as $number) {
            $this->units[$number] = (int)$this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $this->bank = (int)$this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '1111-1', 'kind' => 'corrente'])['id'];
    }

    public function testRateioGoesIntoTheInvoiceBoletoAndPaymentSettlesAll(): void
    {
        $launch = new RevenueLaunch($this->pdo);
        $rateio = $launch->launch($this->admin, ['condominium_id' => $this->alpha, 'apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '80,00',
            'description' => 'Rateio de obra', 'chart_account_id' => $this->fee, 'competence' => '2026-11', 'due_on' => '2026-11-20',
            'reserve_percent' => '10', 'reserve_account_id' => $this->fee, 'invoice_type_id' => $this->type]);
        self::assertSame(4, count($rateio['entries']), 'Rateio e fundo das duas unidades.');
        self::assertSame(4, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_entries WHERE bundle_invoice_type_id = {$this->type}")->fetchColumn());

        $invoices = new Invoices($this->pdo);
        $draft = $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $this->type, 'competence' => '2026-11', 'due_on' => '2026-11-10']);
        $issued = $invoices->issue($this->admin, $draft['id']);
        $byUnit = array_column($issued['items'], 'entry_id', 'unit_id');

        $charges = new BankCharges($this->pdo);
        $charge = $charges->issue($this->admin, (int)$byUnit[$this->units['101']], $this->bank);
        self::assertSame([50000 + 8000 + 800, '2026-11-10'], [$charge['amount_cents'], $charge['due_on']], 'Taxa + rateio + fundo, no vencimento da fatura.');
        $bundled = $charges->find($this->admin, $charge['id'])['bundled'];
        self::assertSame([8000, 800], array_column($bundled, 'amount_cents'));
        $rateio101 = $bundled[0]['entry_id'];

        self::assertSame($charge['id'], $charges->issue($this->admin, $rateio101, $this->bank)['id'], 'Boleto do rateio = o da fatura, sem título novo.');
        try {
            (new EntryCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->alpha, 'entry_ids' => [$rateio101], 'reason' => 'Lançado errado', 'dry_run' => true]);
            self::fail('Cancelou conta que está num boleto ativo.');
        } catch (RegistryValidationException $error) {
            self::assertStringContainsString('boleto', $error->errors['_form']);
        }

        (new BankReturns($this->pdo))->process($this->admin, $this->bank, 'r1.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'P1', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => 58800, 'occurred_on' => '2026-11-09'],
        ]));
        $ids = array_merge([(int)$byUnit[$this->units['101']]], array_column($bundled, 'entry_id'));
        self::assertSame(['2026-11-09', '2026-11-09', '2026-11-09'], array_values(array_map('strval',
            $this->pdo->query('SELECT paid_on FROM erp_entries WHERE id IN (' . implode(',', $ids) . ') ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN))), 'O pagamento baixa as três contas.');
        self::assertSame('bank_confirmed', PaymentLocks::find($this->pdo, [$rateio101])[$rateio101]['code'] ?? null, 'Baixa do banco trava o estorno do rateio também.');

        $this->pdo->exec("UPDATE erp_units SET block_bill_merge = 1 WHERE id = {$this->units['102']}");
        $alone = $charges->issue($this->admin, (int)$byUnit[$this->units['102']], $this->bank);
        self::assertSame(50000, $alone['amount_cents'], 'Unidade com unificação bloqueada: a fatura sai sozinha.');
        $rateio102 = (int)$this->pdo->query("SELECT id FROM erp_entries WHERE unit_id = {$this->units['102']} AND amount_cents = 8000")->fetchColumn();
        self::assertSame(8000, $charges->issue($this->admin, $rateio102, $this->bank)['amount_cents'], 'E o rateio dela tem boleto próprio.');
    }

    public function testWithoutTypeOrAfterTheInvoiceBoletoTheRateioKeepsItsOwnBoleto(): void
    {
        $invoices = new Invoices($this->pdo);
        $draft = $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $this->type, 'competence' => '2026-11', 'due_on' => '2026-11-10']);
        $byUnit = array_column($invoices->issue($this->admin, $draft['id'])['items'], 'entry_id', 'unit_id');
        $charges = new BankCharges($this->pdo);
        $first = $charges->issue($this->admin, (int)$byUnit[$this->units['101']], $this->bank);
        self::assertSame(50000, $first['amount_cents']);

        $launch = new RevenueLaunch($this->pdo);
        $late = $launch->launch($this->admin, ['condominium_id' => $this->alpha, 'apportion' => 'chosen', 'method' => 'fixed', 'amount_cents' => '30,00', 'unit_ids' => [$this->units['101']],
            'description' => 'Rateio tardio', 'chart_account_id' => $this->fee, 'competence' => '2026-11', 'due_on' => '2026-11-20', 'invoice_type_id' => $this->type]);
        $own = $charges->issue($this->admin, $late['entries'][0], $this->bank);
        self::assertSame([3000, '2026-11-20'], [$own['amount_cents'], $own['due_on']], 'Boleto da fatura já emitido: o rateio tem o próprio.');

        $plain = $launch->launch($this->admin, ['condominium_id' => $this->alpha, 'apportion' => 'chosen', 'method' => 'fixed', 'amount_cents' => '20,00', 'unit_ids' => [$this->units['102']],
            'description' => 'Sem tipo', 'chart_account_id' => $this->fee, 'competence' => '2026-11', 'due_on' => '2026-11-20']);
        self::assertSame(50000, $charges->issue($this->admin, (int)$byUnit[$this->units['102']], $this->bank)['amount_cents'], 'Sem tipo da fatura, nada entra junto.');
        self::assertNull($this->pdo->query('SELECT bundle_invoice_type_id FROM erp_entries WHERE id = ' . $plain['entries'][0])->fetchColumn() ?: null);

        $this->expectException(InvalidArgumentException::class);
        $launch->launch($this->admin, ['condominium_id' => $this->alpha, 'apportion' => 'all', 'method' => 'fixed', 'amount_cents' => '1,00',
            'description' => 'Tipo inválido', 'chart_account_id' => $this->fee, 'competence' => '2026-11', 'due_on' => '2026-11-20', 'invoice_type_id' => 999999]);
    }
}
