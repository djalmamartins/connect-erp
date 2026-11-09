<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\ManagementReports;
use Source\Services\Erp\Registry\RegistryService;

/** Relatórios gerenciais (ADR 0082) sobre dados reais: rateio, extrato, demonstrativo, lançamentos e escopo. */
final class ManagementReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private ManagementReports $reports;
    private int $expense;
    private int $bankA;
    private int $bankB;
    /** @var list<int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->reports = new ManagementReports($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->expense = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        foreach ([['101', '0,250000'], ['102', '0,750000']] as [$number, $fraction]) {
            $this->units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento', 'ideal_fraction' => $fraction])['id'];
        }
        $this->bankA = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $this->bankB = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '1111-1', 'kind' => 'poupanca'])['id'];
    }

    public function testApportionmentAndStatement(): void
    {
        $month = date('Y-m');
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $this->expense, 'description' => 'Limpeza',
            'amount_cents' => '1.000,01', 'competence' => $month, 'due_on' => date('Y-m-d'), 'paid_on' => date('Y-m-d'), 'bank_account_id' => $this->bankA]);
        $unit = $this->reports->apportionment($this->admin, $this->alpha, $month, 'unit');
        self::assertFalse($unit['blocked']);
        self::assertSame(100001, array_sum(array_column($unit['rows'], 'amount_cents')), 'Rateio soma a despesa.');
        self::assertSame([25000, 75001], array_column($unit['rows'], 'amount_cents'));

        $statement = $this->reports->statement($this->admin, $this->alpha, $month, $month, 'accounts');
        self::assertSame([100001, 100001, 0], [$statement['rows'][0]['planned_cents'], $statement['rows'][0]['realized_cents'], $statement['rows'][0]['open_cents']]);

        $this->pdo->exec("UPDATE erp_units SET ideal_fraction = NULL WHERE id = {$this->units[1]}");
        $blocked = $this->reports->apportionment($this->admin, $this->alpha, $month, 'unit');
        self::assertSame([true, ['102'], []], [$blocked['blocked'], $blocked['missing_fraction'], $blocked['rows']], 'Sem fração, não rateia.');
    }

    public function testAccountStatementWithTransferAndEntriesOrigin(): void
    {
        $today = date('Y-m-d');
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $this->expense, 'description' => 'Limpeza',
            'amount_cents' => '100,00', 'competence' => date('Y-m'), 'due_on' => $today, 'paid_on' => $today, 'bank_account_id' => $this->bankA]);
        (new EntryShortcuts($this->pdo))->transfer($this->admin, ['condominium_id' => $this->alpha, 'from_bank_account_id' => $this->bankB, 'to_bank_account_id' => $this->bankA,
            'amount_cents' => '300,00', 'transferred_on' => $today, 'description' => 'Reforço de caixa']);
        $extract = $this->reports->accountStatement($this->admin, $this->bankA, $today, $today);
        self::assertSame([0, 30000, 10000, 20000], [$extract['opening_cents'], $extract['totals']['in_cents'], $extract['totals']['out_cents'], $extract['totals']['closing_cents']]);
        self::assertSame(20000, end($extract['rows'])['balance_cents']);

        $detailed = $this->reports->entries($this->admin, $this->alpha, date('Y-m'), date('Y-m'), [], true);
        self::assertSame(['manual', 'Movimento', 'paid'], [$detailed['rows'][0]['origin'], $detailed['rows'][0]['bank_account'], $detailed['rows'][0]['situation']]);
    }

    public function testScope(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        self::assertSame([], $this->reports->costCenters($outsider, null, date('Y-m'), date('Y-m'))['rows']);
        $this->expectException(CondominiumAccessDenied::class);
        $this->reports->accountStatement($outsider, $this->bankA, date('Y-m-d'), date('Y-m-d'));
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
