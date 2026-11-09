<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ChartReports;
use Source\Services\Erp\Registry\RegistryService;

/** Gráficos (ADR 0083) sobre dados reais: geral, inadimplência, caixa e escopo. */
final class ChartReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private ChartReports $charts;
    private int $revenue;
    private int $unit;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->charts = new ChartReports($this->pdo);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->revenue = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->unit = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
    }

    public function testGeneralDelinquencyAndCash(): void
    {
        $previous = date('Y-m', strtotime('first day of last month'));
        $current = date('Y-m');
        $old = $this->entry($previous, $previous . '-05', '400,00');
        $this->entry($previous, $previous . '-05', '600,00');
        $this->pdo->exec("UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = {$this->bank} WHERE id = {$old}");

        $general = $this->charts->general($this->admin, $this->alpha, $previous, $current);
        self::assertSame([$previous, $current], $general['labels']);
        self::assertSame([100000, 0], $general['series'][0]['data'], 'Receitas por competência.');
        self::assertSame([60000, 0], $general['series'][3]['data'], 'Vencido em aberto.');

        $rate = $this->charts->delinquency($this->admin, $this->alpha, $previous, $current);
        self::assertSame([60.0, null], $rate['series'][2]['data'], '600 de 1.000 vencidos; mês sem cobrança sem taxa.');

        $cash = $this->charts->cash($this->admin, $this->alpha, $previous, $current);
        self::assertSame([0, 40000], $cash['series'][0]['data'], 'Caixa pelo mês do pagamento.');
        self::assertSame([[0, 40000]], array_column($this->charts->bankAccounts($this->admin, $this->alpha, $previous, $current)['series'], 'data'));
    }

    public function testScope(): void
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Beta', 'active']);
        $outsider = $this->user([(int)$this->pdo->lastInsertId()]);
        $this->entry(date('Y-m'), date('Y-m-d'), '10,00');
        self::assertSame([0], $this->charts->general($outsider, null, date('Y-m'), date('Y-m'))['series'][0]['data']);
        $this->expectException(CondominiumAccessDenied::class);
        $this->charts->cash($outsider, $this->alpha, date('Y-m'), date('Y-m'));
    }

    private function entry(string $competence, string $due, string $amount): int
    {
        return $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'receita', 'chart_account_id' => $this->revenue,
            'unit_id' => $this->unit, 'description' => 'Taxa', 'amount_cents' => $amount, 'competence' => $competence, 'due_on' => $due])['id'];
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
