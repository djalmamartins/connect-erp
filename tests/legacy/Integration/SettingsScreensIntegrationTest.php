<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\ReportScreens;
use Source\Controllers\Erp\Connect\SettingsScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas de configurações (ADR 0144) contra o banco: parâmetros com a origem certa, saldo inicial e previsão
 * orçamentária no condomínio da barra superior, o relatório de previsão (AP-084) e a demonstração sem permissão.
 */
final class SettingsScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $account;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->alpha = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 10]);
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, ?)')->execute(['Developer', 'developer', 100]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, (int)$this->pdo->lastInsertId()]);
        (new CondominiumScope($this->pdo))->grant($userId, $this->alpha);
        $this->admin = (new User())->findById($userId);
        (new RegistryService($this->pdo))->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = (new RegistryService($this->pdo))->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testParametersShowTheSourceForTheCondominium(): void
    {
        $service = new ErpSettings($this->pdo);
        $service->set($this->admin, null, 'alerts.overdue_days', 45);
        $service->set($this->admin, $this->alpha, 'alerts.contract_expiry_days', 10);
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        $data = (new SettingsScreens())->data($this->admin, 'AP-159');
        self::assertTrue($data['settingsReady']);
        $byKey = array_column($data['settingsGroup']['settings'], null, 'key');
        self::assertSame([45, 'connect'], [$byKey['alerts.overdue_days']['value'], $byKey['alerts.overdue_days']['source']]);
        self::assertSame([10, 'condominium'], [$byKey['alerts.contract_expiry_days']['value'], $byKey['alerts.contract_expiry_days']['source']]);
        self::assertTrue($data['canManageSettings']);
        foreach (array_keys(SettingsScreens::PAGES) as $page) {
            self::assertTrue((new SettingsScreens())->data($this->admin, $page)['settingsReady'], "{$page}: tela real");
        }
    }

    public function testOpeningsAndBudgetNeedACondominium(): void
    {
        self::assertTrue((new SettingsScreens())->data($this->admin, 'AP-162')['settingsNeedsCondo']);
        self::assertTrue((new SettingsScreens())->data($this->admin, 'AP-163')['settingsNeedsCondo']);
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        (new ErpSettings($this->pdo))->setBudget($this->admin, $this->alpha, (int)date('Y'), $this->account, array_fill(0, 12, '100,00'));
        $budget = (new SettingsScreens())->data($this->admin, 'AP-163');
        self::assertSame(120000, $budget['budget']['rows'][0]['budget_total']);
        self::assertContains($this->account, array_column($budget['budgetAccounts'], 'id'));
        $openings = (new SettingsScreens())->data($this->admin, 'AP-162');
        self::assertSame([], $openings['openings']['rows']);
        $report = (new ReportScreens())->data($this->admin, 'AP-084');
        self::assertSame([120000, null], [$report['reportRows'][0]['budget_total'], $report['reportCsv']]);
    }

    public function testWithoutPermissionStaysOnTheDemo(): void
    {
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        self::assertSame(['settingsReady' => false], (new SettingsScreens())->data($plain, 'AP-147'));
    }
}
