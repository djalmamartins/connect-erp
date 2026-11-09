<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryService;

/** Configurações (ADR 0085): valor por condomínio sobre o padrão, auditoria, orçamento, saldo inicial e frações. */
final class ErpSettingsIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private ErpSettings $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->settings = new ErpSettings($this->pdo);
    }

    public function testCondominiumOverridesDefaultAndIsAudited(): void
    {
        self::assertSame(10, ErpSettings::value($this->pdo, $this->alpha, 'finance.default_due_day'), 'Sem nada gravado, vale o padrão de hoje.');
        $this->settings->set($this->admin, $this->alpha, 'finance.default_due_day', '15');
        self::assertSame(15, ErpSettings::value($this->pdo, $this->alpha, 'finance.default_due_day'));
        self::assertSame(10, ErpSettings::value($this->pdo, null, 'finance.default_due_day'), 'O padrão da Connect não muda.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_settings'")->fetchColumn());
        $this->settings->reset($this->admin, $this->alpha, 'finance.default_due_day');
        self::assertSame(10, ErpSettings::value($this->pdo, $this->alpha, 'finance.default_due_day'));

        try {
            $this->settings->set($this->admin, $this->alpha, 'params.report_max_rows', '1000');
            self::fail('Mudou chave global por condomínio.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(RegistryForbidden::class);
        $this->settings->set($this->admin, null, 'finance.default_due_day', '12');
    }

    public function testBudgetComparesWithEntriesAndFractionsNeedAllUnits(): void
    {
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $account = $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $year = (int)date('Y');
        $month = (int)date('n');
        $months = array_fill(0, 12, '1.000,00');
        $this->service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $account, 'description' => 'Limpeza',
            'amount_cents' => '1.200,00', 'competence' => date('Y-m'), 'due_on' => date('Y-m-d')]);
        $budget = $this->settings->setBudget($this->admin, $this->alpha, $year, $account, $months);
        self::assertSame([1200000, 120000], [$budget['rows'][0]['budget_total'], $budget['rows'][0]['actual_total']]);
        self::assertSame(120000, $budget['rows'][0]['actual'][$month]);

        $units = [];
        foreach (['101', '102'] as $number) {
            $units[] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        try {
            $this->settings->setFractions($this->admin, $this->alpha, [$units[0] => '0,5']);
            self::fail('Aceitou frações sem todas as unidades.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        self::assertSame(1.0, $this->settings->setFractions($this->admin, $this->alpha, [$units[0] => '40%', $units[1] => '0,6'])['fraction_sum']);
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
