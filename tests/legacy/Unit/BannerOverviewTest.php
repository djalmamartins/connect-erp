<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BannerOverview;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0286: situação dos banners do aplicativo, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (no ar, agendado, encerrado e inativo nos limites do dia; por alcance, com totais iguais à soma;
 * ids na ordem de exibição, acabando em breve e encerrados ainda ativos, com o corte), a montagem sem título nem texto
 * (outro condomínio e apagados de fora), a validação, o escopo e o contrato. Situações e alcances vêm das constantes, e a
 * tabela e a permissão do cadastro de banners do registro; nada de quantidade nem lista fixa no teste. O MariaDB confere
 * com banners de verdade (Integration\BannerOverviewTest).
 */
final class BannerOverviewTest extends TestCase
{
    private PDO $pdo;
    private BannerOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_banners (id INTEGER PRIMARY KEY, condominium_id INTEGER NULL, title TEXT, message TEXT, starts_on TEXT, ends_on TEXT, position INTEGER NULL,
                status TEXT, created_by INTEGER, updated_by INTEGER, created_at TEXT, updated_at TEXT, deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $insert = $this->pdo->prepare("INSERT INTO erp_banners (id, condominium_id, title, message, starts_on, ends_on, position, status, created_by, deleted_at)
            VALUES (?, ?, 'Recado do Fulano', 'Ligue para Fulano de Tal', ?, ?, ?, ?, 7, ?)");
        foreach ([[1, 1, '2026-11-01', '2026-11-30', 2, 'active', null], [2, 1, '2026-11-10', '2026-11-18', 1, 'active', null],
            [3, 1, '2026-11-20', '2026-12-10', null, 'active', null], [4, 1, '2026-10-01', '2026-11-13', null, 'active', null],
            [5, 1, '2026-11-01', '2026-11-30', null, 'inactive', null], [6, null, '2026-11-14', '2026-11-14', null, 'active', null],
            [7, null, '2026-01-01', '2026-02-01', null, 'active', null], [8, 3, '2026-11-01', '2026-11-30', 1, 'active', null],
            [9, 1, '2026-11-01', '2026-11-30', 1, 'active', '2026-11-02 10:00:00'], [10, 1, '2026-11-14', '2026-11-21', 1, 'active', null]] as $row) {
            $insert->execute($row);
        }
        $this->service = new BannerOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testStateAtTheDayLimits(): void
    {
        $today = '2026-11-14';
        self::assertSame('showing', BannerOverview::state('active', $today, $today, $today), 'Começa e acaba hoje: no ar.');
        self::assertSame('scheduled', BannerOverview::state('active', '2026-11-15', '2026-11-20', $today));
        self::assertSame('ended', BannerOverview::state('active', '2026-11-01', '2026-11-13', $today));
        self::assertSame('inactive', BannerOverview::state('inactive', '2026-11-01', '2026-11-30', $today), 'Inativo vale antes das datas.');
        foreach (['showing', 'scheduled', 'ended', 'inactive'] as $state) {
            self::assertArrayHasKey($state, BannerOverview::STATES);
        }
    }

    public function testSummaryFromTheCondominium(): void
    {
        $report = $this->service->build($this->user(), '1', '2026-11-14');
        self::assertSame(1, $report['condominium_id']);
        self::assertSame(array_keys(BannerOverview::SCOPES), array_column($report['rows'], 'scope'), 'Todos os alcances, sempre.');
        $rows = array_combine(array_column($report['rows'], 'scope'), $report['rows']);
        self::assertSame([6, 3, 1, 1, 1], [$rows['condominium']['banners'], $rows['condominium']['showing'], $rows['condominium']['scheduled'],
            $rows['condominium']['ended'], $rows['condominium']['inactive']], 'Sem o apagado e sem o de outro condomínio.');
        self::assertSame([2, 1, 0, 1, 0], [$rows['global']['banners'], $rows['global']['showing'], $rows['global']['scheduled'], $rows['global']['ended'],
            $rows['global']['inactive']]);
        foreach (['banners', ...array_keys(BannerOverview::STATES)] as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['banners'], array_sum(array_intersect_key($row, BannerOverview::STATES)), 'As situações somam os banners.');
        }
        self::assertSame($report['totals']['showing'], $report['showing_now']);
        self::assertSame([[2, 10, 1, 6], false], [$report['showing_ids'], $report['showing_truncated']], 'Pela posição, depois pelo início; sem posição no fim.');
        self::assertSame([[6, 2, 10], false], [$report['ending_soon_ids'], $report['ending_soon_truncated']], 'Do que acaba antes; o limite de 7 dias entra.');
        self::assertSame([[4, 7], false], [$report['ended_active_ids'], $report['ended_active_truncated']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Recado', 'Ligue', 'created_by', 'title', 'message'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= BannerOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'global' => false, 'status' => 'active', 'starts_on' => '2026-11-01', 'ends_on' => '2026-11-15', 'position' => null];
            $many[] = ['id' => 1000 + $i, 'global' => true, 'status' => 'active', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-02', 'position' => null];
        }
        $report = BannerOverview::summarize($many, '2026-11-14');
        self::assertCount(BannerOverview::MAX_IDS, $report['showing_ids']);
        self::assertTrue($report['showing_truncated']);
        self::assertTrue($report['ending_soon_truncated']);
        self::assertTrue($report['ended_active_truncated']);
        self::assertSame(BannerOverview::MAX_IDS + 1, $report['showing_now'], 'A contagem não é cortada.');
        $empty = BannerOverview::summarize([], '2026-11-14');
        self::assertSame(count(BannerOverview::SCOPES), count($empty['rows']), 'Sem banner, as linhas aparecem zeradas.');
        self::assertSame(0, $empty['totals']['banners']);
    }

    public function testValidationAndScope(): void
    {
        foreach ([null, '', 0, 'abc', '-1', []] as $value) {
            try {
                $this->service->build($this->user(), $value, '2026-11-14');
                self::fail('Condomínio inválido passou: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, '2026-11-14');
    }

    public function testReadOnlyContract(): void
    {
        $definition = RegistryService::definition('banners');
        self::assertSame('erp_banners', $definition['table']);
        self::assertSame('optional', $definition['scope'], 'Sem condomínio vale para todos.');
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0540_settings.sql');
        self::assertMatchesRegularExpression("/CREATE TABLE IF NOT EXISTS `erp_banners`.*?`status` enum\\('active','inactive'\\)/s", $sql, 'A situação ativa é a da enum.');
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/BannerOverview.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('banners')", 'SELECT NOW()', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'title', 'message', 'created_by', 'updated_by'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem título, texto ou quem cadastrou: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/BannerOverview.php');
        foreach (["Access::can('erp.access', \$user)", "RegistryService::definition('banners')['permission'] . '.view'", 'Access::can($permission, $user)',
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertSame('erp.settings', $definition['permission'], 'A permissão da tela de banners (AP-156).');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/banners", "BannerOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/imports/discard-stale"'), strpos($index, $route), 'Logo depois do descarte de prévias.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /imports/discard-stale:\n"), strpos($openapi, "  /reports/banners:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
