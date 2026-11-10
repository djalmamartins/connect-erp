<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\ReportScreens;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Erp\CondominiumContext;

/**
 * Tela única dos relatórios (ADR 0143) contra o banco: as 36 páginas leem pelo serviço da API, com um
 * condomínio na barra superior e com "Todos"; o rateio e a memória de cálculo pedem o condomínio; quem
 * não tem a permissão continua na demonstração.
 */
final class ReportScreensIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;

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
        $service = new RegistryService($this->pdo);
        $service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $expense = $service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => '101', 'type' => 'apartamento', 'ideal_fraction' => '1,000000']);
        $bank = $service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $service->save($this->admin, 'entries', ['condominium_id' => $this->alpha, 'nature' => 'despesa', 'chart_account_id' => $expense, 'description' => 'Limpeza',
            'amount_cents' => '1.000,01', 'competence' => date('Y-m'), 'due_on' => date('Y-m-d'), 'paid_on' => date('Y-m-d'), 'bank_account_id' => $bank]);
        $_GET = [];
    }

    protected function tearDown(): void
    {
        CondominiumContext::use([], null);
        $_GET = [];
        parent::tearDown();
    }

    public function testEveryPageReadsRealDataWithACondominium(): void
    {
        CondominiumContext::use([['id' => $this->alpha, 'name' => 'Alpha']], $this->alpha);
        foreach (array_keys(ReportScreens::PAGES) as $page) {
            $data = (new ReportScreens())->data($this->admin, $page);
            self::assertTrue($data['reportReady'], "{$page}: tela real");
            self::assertSame('pages/reports/report', Panel::ownTemplate($page, $data));
            self::assertNull($data['reportError'], "{$page}: " . (string)$data['reportError']);
            self::assertIsArray($data['report'], "{$page}: relatório");
            if ((ReportScreens::PAGES[$page]['csv'] ?? true) !== false) {
                self::assertStringStartsWith('/api/v1/reports/', (string)$data['reportCsv']);
            }
        }
        $apportionment = (new ReportScreens())->data($this->admin, 'AP-062');
        self::assertSame(100001, array_sum(array_column($apportionment['reportRows'], 'amount_cents')));
        $statement = (new ReportScreens())->data($this->admin, 'AP-056');
        self::assertNotNull($statement['reportFilters']['conta'], 'AP-056: escolhe a primeira conta');
        self::assertSame(-100001, $statement['report']['totals']['closing_cents']);
        self::assertSame(100001, (new ReportScreens())->data($this->admin, 'AP-080')['report']['totals']['expense_cents']);
        self::assertSame(100001, (new ReportScreens())->data($this->admin, 'AP-082')['report']['totals']['expense_cents']);
        $ledger = (new ReportScreens())->data($this->admin, 'AP-083');
        self::assertSame(['Saldo anterior', 'Limpeza', 'Saldo final'], array_column($ledger['reportRows'], 'description'));
        self::assertSame(-100001, (new ReportScreens())->data($this->admin, 'AP-073')['reportRows'][0]['closing_cents']);
    }

    public function testAllCondominiumsNeedChoiceAndInvalidPeriodShowsError(): void
    {
        $data = (new ReportScreens())->data($this->admin, 'AP-075');
        self::assertTrue($data['reportNeedsCondo']);
        self::assertNull($data['report']);
        self::assertSame(1, count((new ReportScreens())->data($this->admin, 'AP-068')['reportRows']));
        $_GET = ['de' => '2020-01', 'ate' => '2026-01'];
        self::assertSame('No máximo 24 competências.', (new ReportScreens())->data($this->admin, 'AP-052')['reportError']);
    }

    public function testWithoutPermissionStaysOnTheDemo(): void
    {
        $plain = (new User())->findById($this->createUser(['level' => 1]));
        self::assertSame(['reportReady' => false], (new ReportScreens())->data($plain, 'AP-068'));
    }
}
