<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PaymentReports;
use Source\Services\Erp\Registry\RegistryService;

/** Pagamentos (AP-074), memória de cálculo (AP-075) e transferências (AP-076), ADR 0089. */
final class PaymentReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->registry = new RegistryService($this->pdo);
    }

    public function testPaymentsCalculationAndTransfers(): void
    {
        $r = $this->registry;
        $bank = $r->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $saving = $r->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '9999-0', 'kind' => 'poupanca'])['id'];
        $r->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $income = $r->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $r->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $expense = $r->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Manutenção'])['id'];
        $unit = $r->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $today = date('Y-m-d');
        $r->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $expense, 'bank_account_id' => $bank,
            'description' => 'Bomba', 'amount_cents' => '150,00', 'competence' => substr($today, 0, 7), 'due_on' => $today, 'paid_on' => $today]);
        $due = date('Y-m-d', strtotime('-45 days'));
        $r->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $income, 'unit_id' => $unit,
            'description' => 'Taxa', 'amount_cents' => '400,00', 'competence' => substr($due, 0, 7), 'due_on' => $due]);

        $service = new PaymentReports($this->pdo);
        $from = date('Y-m-01');
        $payments = $service->payments($this->admin, $this->alpha, $from, $today);
        self::assertSame(['entries' => 1, 'amount_cents' => 15000, 'by_bank_account' => ['Movimento' => 15000]], $payments['totals']);
        self::assertSame(0, $service->payments($this->admin, $this->alpha, $from, $today, 'receita')['totals']['entries'], 'Receita em aberto não é pagamento.');

        $calc = $service->calculation($this->admin, $this->alpha, (int)$unit);
        self::assertSame(1, $calc['totals']['entries']);
        $row = $calc['rows'][0];
        self::assertSame(45, $row['days_late']);
        self::assertSame(40000, $row['principal_cents']);
        self::assertSame($row['principal_cents'] + $row['correction_cents'] + $row['fine_cents'] + $row['interest_cents'], $row['total_cents']);
        self::assertSame(count($row['index_steps']), count(\Source\Services\Erp\LateCharges::indexMonths($due, $today)));

        $this->pdo->prepare("INSERT INTO erp_bank_transfers (condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description, status)
            VALUES (?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?)")->execute([$this->alpha, $bank, $saving, 50000, $today, 'Fundo de reserva', 'active',
            $this->alpha, $bank, $saving, 9900, $today, 'Lançada errada', 'cancelled']);
        $transfers = $service->transfers($this->admin, $this->alpha, $from, $today);
        self::assertSame(['transfers' => 1, 'amount_cents' => 50000, 'cancelled' => 1], $transfers['totals']);
        self::assertSame(['Movimento', 'Reserva'], [$transfers['rows'][0]['from_account'], $transfers['rows'][0]['to_account']]);
        self::assertCount(1, $service->transfers($this->admin, $this->alpha, $from, $today, false)['rows']);
    }

    public function testScopeAndPeriod(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $other = $this->user([(int)$this->pdo->lastInsertId()]);
        $service = new PaymentReports($this->pdo);
        foreach ([fn () => $service->payments($other, $this->alpha, date('Y-m-01'), date('Y-m-d')), fn () => $service->calculation($other, $this->alpha),
            fn () => $service->transfers($other, $this->alpha, date('Y-m-01'), date('Y-m-d'))] as $call) {
            try {
                $call();
                self::fail('Relatório fora do escopo.');
            } catch (CondominiumAccessDenied) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([], $service->payments($other, null, date('Y-m-01'), date('Y-m-d'))['rows'], 'Sem condomínio: só o escopo do usuário.');
        $this->expectException(InvalidArgumentException::class);
        $service->payments($this->admin, $this->alpha, '2025-01-01', '2026-06-01');
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        return (new User())->findById($id);
    }
}
