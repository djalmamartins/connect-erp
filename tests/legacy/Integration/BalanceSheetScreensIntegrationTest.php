<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\BalanceSheetScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\CondominiumContext;

/**
 * Tela do Balanço Patrimonial (ADR 0148) contra o banco: pede o condomínio da barra, monta o balanço pelo mesmo
 * serviço da API (contas a receber com o vencido) e, sem permissão, deixa a demonstração.
 */
final class BalanceSheetScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private int $revenue;

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
        $registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->revenue = (int)$registry->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testBalanceSheetOfTheTopBarCondominium(): void
    {
        $data = (new BalanceSheetScreens())->data($this->admin, 'AP-081');
        self::assertTrue($data['balanceReady']);
        self::assertTrue($data['balanceNeedsCondo']);
        self::assertNull($data['balance']);

        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        $month = date('Y-m', strtotime('first day of last month'));
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on)
            VALUES (?, 'receita', ?, 'Taxa vencida', 30000, ?, ?)")->execute([$this->alpha, $this->revenue, $month, $month . '-10']);
        $_GET = ['data' => date('Y-m-d')];
        $data = (new BalanceSheetScreens())->data($this->admin, 'AP-081');
        self::assertFalse($data['balanceNeedsCondo']);
        self::assertNull($data['balanceError']);
        $sheet = $data['balance'];
        self::assertSame($this->alpha, $sheet['condominium_id']);
        self::assertSame(date('Y-m-d'), $sheet['date']);
        self::assertSame(30000, $sheet['totals']['assets_cents']);
        $receivable = array_values(array_filter($sheet['assets']['rows'], static fn (array $r): bool => ($r['role'] ?? null) === 'receivable'))[0];
        self::assertSame(30000, $receivable['overdue_cents']);
        self::assertSame(30000, $sheet['totals']['equity_cents'], 'A receita do ano vira resultado do exercício.');
        self::assertTrue($sheet['balanced']);

        $_GET = ['data' => '2999-01-01'];
        self::assertSame(date('Y-m-d'), (new BalanceSheetScreens())->data($this->admin, 'AP-081')['balanceDate'], 'Data futura volta para hoje.');
    }

    public function testWithoutPermissionKeepsTheDemo(): void
    {
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        self::assertSame(['balanceReady' => false], (new BalanceSheetScreens())->data($plain, 'AP-081'));
    }
}
