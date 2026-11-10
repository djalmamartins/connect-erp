<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\AccountabilityScreens;
use Source\Controllers\Erp\Connect\DeliveryScreens;
use Source\Controllers\Erp\Connect\SettingsScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\ChartOpenings;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\CondominiumContext;

/**
 * Telas do ciclo 55 (ADR 0146) contra o banco: saldo inicial do plano de contas (AP-161) com a soma na
 * sintética, registros de entregas (AP-136) com filtro e escopo, e a prestação de contas (AP-101) do mês.
 */
final class Cycle55ScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $unit;
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
        $registry = new RegistryService($this->pdo);
        $this->unit = (int)$registry->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento'])['id'];
        $registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = (int)$registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testChartOpeningsScreen(): void
    {
        self::assertTrue((new SettingsScreens())->data($this->admin, 'AP-161')['settingsNeedsCondo']);
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        (new ChartOpenings($this->pdo))->set($this->admin, $this->alpha, $this->account, date('Y-01-01'), '-50,00');
        $data = (new SettingsScreens())->data($this->admin, 'AP-161');
        self::assertTrue($data['settingsReady']);
        self::assertSame(['2', '2.01'], array_column($data['chartOpenings']['rows'], 'code'));
        self::assertSame([-5000, -5000], array_column($data['chartOpenings']['rows'], 'amount_cents'), 'A sintética soma a analítica.');
        self::assertContains($this->account, array_column($data['openingAccounts'], 'id'));
    }

    public function testDeliveriesScreen(): void
    {
        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on, status, created_by) VALUES (?, ?, 'Ana', 'correios', ?, 'active', ?)")
            ->execute([$this->alpha, $this->unit, date('Y-m-d', strtotime('-3 days')), (int)$this->admin->id]);
        $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, unit_id, recipient, carrier, received_on, status, picked_up_on, picked_up_by) VALUES (?, ?, 'Bia', 'amazon', ?, 'picked_up', ?, 'Bia')")
            ->execute([$this->alpha, $this->unit, date('Y-m-d', strtotime('-5 days')), date('Y-m-d', strtotime('-4 days'))]);
        $all = (new DeliveryScreens())->data($this->admin, 'AP-136');
        self::assertTrue($all['deliveryReady']);
        self::assertTrue($all['canPickUp']);
        self::assertSame(['waiting' => 1, 'picked_up' => 1], array_intersect_key($all['deliveryLog']['summary'], ['waiting' => 0, 'picked_up' => 0]));
        $_GET = ['situacao' => 'active'];
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        $active = (new DeliveryScreens())->data($this->admin, 'AP-136');
        self::assertSame(['Ana'], array_column($active['deliveryLog']['rows'], 'recipient'));
        self::assertSame([$this->unit], array_column($active['deliveryUnits'], 'id'));
        $_GET = ['de' => '2026-01-01', 'ate' => '2027-06-01'];
        self::assertSame('Período máximo de 366 dias.', (new DeliveryScreens())->data($this->admin, 'AP-136')['deliveryError']);
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        self::assertSame(['deliveryReady' => false], (new DeliveryScreens())->data($plain, 'AP-136'));
    }

    public function testAccountabilityScreen(): void
    {
        $data = (new AccountabilityScreens())->data($this->admin, 'AP-101');
        self::assertTrue($data['accountabilityReady']);
        self::assertTrue($data['accountabilityNeedsCondo']);
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        $_GET = ['mes' => date('Y-m')];
        $data = (new AccountabilityScreens())->data($this->admin, 'AP-101');
        self::assertNull($data['accountabilityError']);
        self::assertSame(date('Y-m'), $data['accountability']['competence']);
        self::assertContains('month_open', array_column($data['accountability']['warnings'], 'code'));
    }
}
