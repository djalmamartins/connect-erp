<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseOverview;
use Source\Services\Erp\Registry\RegistryService;

/**
 * ADR 0294: situação das portarias, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura (linhas por tipo com totais iguais à soma, acessos e encomendas fora das portarias ativas à parte, portarias
 * sem acesso e o corte), a montagem sem nome, responsável, telefone nem pessoa (período nos limites, acesso cancelado ou
 * excluído, encomenda retirada, portaria excluída e outro condomínio de fora), a validação, o escopo e o contrato. Os tipos
 * vêm do cadastro; nada de quantidade nem lista fixa. O MariaDB confere com portarias de verdade
 * (Integration\GatehouseOverviewTest).
 */
final class GatehouseOverviewTest extends TestCase
{
    private PDO $pdo;
    private GatehouseOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_gatehouses (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, kind TEXT, manager_name TEXT, phone TEXT, status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_access_logs (id INTEGER PRIMARY KEY, condominium_id INTEGER, person_name TEXT, person_document TEXT, entered_on TEXT, gatehouse_id INTEGER NULL,
                status TEXT, deleted_at TEXT NULL)',
            'CREATE TABLE erp_deliveries (id INTEGER PRIMARY KEY, condominium_id INTEGER, recipient TEXT, received_on TEXT, gatehouse_id INTEGER NULL, status TEXT,
                deleted_at TEXT NULL)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b, $c] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $gate = $this->pdo->prepare("INSERT INTO erp_gatehouses (id, condominium_id, name, kind, manager_name, phone, status, deleted_at)
            VALUES (?, ?, 'Portaria do Fulano', ?, 'Fulano de Tal', '11999990000', ?, ?)");
        foreach ([[1, 1, $a, 'active', null], [2, 1, $b, 'active', null], [3, 1, $a, 'inactive', null], [4, 1, $c, 'active', null],
            [5, 1, $a, 'active', '2026-11-01 10:00:00'], [9, 3, $a, 'active', null]] as $row) {
            $gate->execute($row);
        }
        $log = $this->pdo->prepare("INSERT INTO erp_access_logs (condominium_id, person_name, person_document, entered_on, gatehouse_id, status, deleted_at)
            VALUES (?, 'Visitante Fulano', '123.456.789-00', ?, ?, ?, ?)");
        foreach ([[1, '2026-11-01', 1, 'active', null], [1, '2026-11-14', 1, 'active', null], [1, '2026-10-16', 1, 'active', null], [1, '2026-10-15', 1, 'active', null],
            [1, '2026-11-02', 1, 'inactive', null], [1, '2026-11-03', 1, 'active', '2026-11-04 10:00:00'], [1, '2026-11-05', 3, 'active', null],
            [1, '2026-11-06', 3, 'active', null], [1, '2026-11-07', null, 'active', null], [1, '2026-11-08', 4, 'active', null], [3, '2026-11-08', 9, 'active', null]] as $row) {
            $log->execute($row);
        }
        $delivery = $this->pdo->prepare("INSERT INTO erp_deliveries (condominium_id, recipient, received_on, gatehouse_id, status, deleted_at)
            VALUES (?, 'Morador Fulano', ?, ?, ?, NULL)");
        foreach ([[1, '2026-11-01', 1, 'active'], [1, '2026-10-20', 1, 'active'], [1, '2026-11-10', 2, 'active'], [1, '2026-09-01', null, 'active'],
            [1, '2026-10-01', 3, 'active'], [1, '2026-08-01', 1, 'picked_up'], [3, '2026-01-01', 9, 'active']] as $row) {
            $delivery->execute($row);
        }
        $this->service = new GatehouseOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<string> os três primeiros tipos, vindos do cadastro */
    private static function kinds(): array
    {
        $kinds = array_keys(GatehouseOverview::kinds());
        self::assertGreaterThanOrEqual(3, count($kinds));
        return array_slice($kinds, 0, 3);
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$a, $b, $c] = self::kinds();
        $report = $this->service->build($this->user(), '1', '30', '2026-11-14');
        self::assertSame([1, 30, '2026-10-16', '2026-11-14'], [$report['condominium_id'], $report['days'], $report['from'], $report['to']]);
        self::assertSame(array_keys(GatehouseOverview::kinds()), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        $pick = static fn (array $r): array => [$r['gatehouses'], $r['inactive'], $r['entries'], $r['pending_deliveries'], $r['oldest_pending_on']];
        self::assertSame([1, 1, 3, 2, '2026-10-20'], $pick($rows[$a]), 'Sem o acesso fora do período, o cancelado, o excluído, a retirada e a portaria excluída.');
        self::assertSame([1, 0, 0, 1, '2026-11-10'], $pick($rows[$b]));
        self::assertSame([1, 0, 1, 0, null], $pick($rows[$c]));
        foreach (['gatehouses', 'inactive', 'entries', 'pending_deliveries'] as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        self::assertSame('2026-10-20', $report['totals']['oldest_pending_on']);
        self::assertSame(['entries' => 3, 'pending_deliveries' => 2, 'oldest_pending_on' => '2026-09-01'], $report['elsewhere'],
            'Sem portaria ou em portaria inativa, à parte.');
        self::assertSame([7, 5], [$report['all_entries'], $report['all_pending']]);
        self::assertSame($report['all_entries'], $report['totals']['entries'] + $report['elsewhere']['entries']);
        self::assertSame([[2], false], [$report['idle_ids'], $report['idle_truncated']], 'A portaria ativa sem acesso no período.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Portaria do', '11999', '123.456', 'manager', 'phone', 'name', 'document'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdleCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= GatehouseOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'kind' => self::kinds()[0], 'status' => 'active'];
        }
        $report = GatehouseOverview::summarize($many, [], []);
        self::assertCount(GatehouseOverview::MAX_IDS, $report['idle_ids']);
        self::assertTrue($report['idle_truncated']);
        self::assertSame(GatehouseOverview::MAX_IDS + 1, $report['totals']['gatehouses'], 'A contagem não é cortada.');
        $empty = GatehouseOverview::summarize([], ['' => 4], ['' => ['count' => 1, 'oldest' => '2026-11-01']]);
        self::assertSame([count(GatehouseOverview::kinds()), 0, 4, 1], [count($empty['rows']), $empty['totals']['gatehouses'], $empty['all_entries'], $empty['all_pending']],
            'Sem portaria cadastrada, tudo fica à parte.');
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(GatehouseOverview::MAX_DAYS + 1)], [[], null]] as [$c, $d]) {
            try {
                $this->service->build($this->user(), $c, $d, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $d]));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, null, '2026-11-14');
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertSame(['erp_gatehouses', 'erp_access_logs', 'erp_deliveries'], [RegistryService::definition('gatehouses')['table'],
            RegistryService::definition('access_logs')['table'], RegistryService::definition('deliveries')['table']]);
        $sql = (string)file_get_contents($root . '/database/migrations/20261108_0525_gatehouse.sql');
        self::assertMatchesRegularExpression("/CREATE TABLE IF NOT EXISTS `erp_deliveries`.*?`status` enum\\('active','picked_up','inactive'\\)/s", $sql,
            'Aguardando retirada é active.');
        $service = (string)file_get_contents($root . '/source/Services/Erp/GatehouseOverview.php');
        foreach (['$this->scope->assertAccess(', "RegistryService::definition('gatehouses')", "RegistryService::definition('access_logs')",
            "RegistryService::definition('deliveries')", 'SELECT NOW()', "deleted_at IS NULL AND status = 'active'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'person_', 'manager_name', 'phone', 'recipient', 'SELECT id, name'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem nome, responsável, telefone ou pessoa: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/GatehouseOverview.php');
        foreach (["Access::can('erp.access', \$user)", "['gatehouses', 'access_logs', 'deliveries']", "RegistryService::definition(\$slug)['permission'] . '.view'",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/gatehouses", "GatehouseOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/taxes/deactivate-redundant"'), strpos($index, $route), 'Logo depois do lote de alíquotas.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /taxes/deactivate-redundant:\n"), strpos($openapi, "  /reports/gatehouses:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
