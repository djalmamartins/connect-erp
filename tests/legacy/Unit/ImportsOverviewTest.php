<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\ImportsOverview;

/**
 * ADR 0284: situação das importações, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura (prévias e aplicadas por tipo, linhas e erros, última aplicação, idade das prévias, ids, totais iguais à
 * soma), a montagem sem nome de arquivo, caminho nem hash (outro condomínio e tipo desconhecido de fora), a validação, o
 * escopo e o contrato. Tipos, situações e faixas vêm das próprias constantes (Imports::KINDS, STATUSES e AGES), e
 * STATUSES bate com a enum da migration; nada de quantidade nem lista fixa no teste. O MariaDB confere com importações
 * de verdade (Integration\ImportsOverviewTest).
 */
final class ImportsOverviewTest extends TestCase
{
    private PDO $pdo;
    private ImportsOverview $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_imports (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, original_name TEXT, sha256 TEXT, storage_path TEXT, status TEXT,
                rows_count INTEGER, error_count INTEGER, summary_json TEXT, created_by INTEGER, created_at TEXT, applied_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        [$a, $b] = self::kinds();
        $insert = $this->pdo->prepare("INSERT INTO erp_imports (id, condominium_id, kind, original_name, sha256, storage_path, status, rows_count, error_count, summary_json,
            created_by, created_at, applied_at) VALUES (?, ?, ?, 'extrato-fulano.ofx', 'abc123hash', 'storage/private/imports/1/x.ofx', ?, ?, ?, '{\"pagador\":\"Fulano\"}', 7, ?, ?)");
        foreach ([[1, 1, $a, 'previewed', 10, 0, '2026-11-12 09:00:00', null], [2, 1, $a, 'applied', 20, 2, '2026-10-01 09:00:00', '2026-10-02 10:00:00'],
            [3, 1, $a, 'applied', 5, 0, '2026-10-31 09:00:00', '2026-11-01 08:00:00'], [4, 1, $b, 'previewed', 7, 3, '2026-09-01 09:00:00', null],
            [5, 1, $b, 'previewed', 1, 0, '2026-10-25 09:00:00', null], [6, 3, $a, 'previewed', 9, 9, '2026-01-01 09:00:00', null],
            [7, 1, 'tipo_desconhecido', 'previewed', 1, 1, '2026-11-01 09:00:00', null]] as $row) {
            $insert->execute($row);
        }
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->service = new ImportsOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos de Imports::KINDS */
    private static function kinds(): array
    {
        $kinds = array_keys(Imports::KINDS);
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[count($kinds) - 1]];
    }

    public function testStatusesMatchTheMigrationEnum(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261114_0001_import_discard.sql');   // a última que muda a enum (ADR 0285)
        preg_match("/`status` enum\\(([^)]*)\\)/", $sql, $status);
        self::assertSame(array_keys(ImportsOverview::STATUSES), array_map('trim', explode(',', str_replace("'", '', $status[1]))));
    }

    public function testAgeBucketsComeFromTheConstant(): void
    {
        $ages = array_keys(ImportsOverview::AGES);
        self::assertSame($ages[0], ImportsOverview::age(0));
        foreach (ImportsOverview::AGES as $age => $limit) {
            if ($limit !== null) {
                self::assertSame($age, ImportsOverview::age($limit), "{$limit} dias ainda é {$age}.");
                self::assertNotSame($age, ImportsOverview::age($limit + 1), "{$limit} + 1 dias já passa de {$age}.");
            }
        }
        self::assertSame(array_key_last(ImportsOverview::AGES), ImportsOverview::age(100000));
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$a, $b] = self::kinds();
        $report = $this->service->build($this->user(), '1', $this->now);
        self::assertSame(array_keys(Imports::KINDS), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        self::assertSame([3, 1, 2, 35, 2, 1, '2026-11-01'], [$rows[$a]['imports'], $rows[$a]['previewed'], $rows[$a]['applied'], $rows[$a]['rows'], $rows[$a]['errors'],
            $rows[$a]['with_errors'], $rows[$a]['last_applied_on']]);
        self::assertSame([2, 2, 0, 8, 3, 1, null], [$rows[$b]['imports'], $rows[$b]['previewed'], $rows[$b]['applied'], $rows[$b]['rows'], $rows[$b]['errors'],
            $rows[$b]['with_errors'], $rows[$b]['last_applied_on']]);
        foreach (['imports', 'previewed', 'applied', 'rows', 'errors', 'with_errors'] as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['imports'], array_sum(array_intersect_key($row, ImportsOverview::STATUSES)), 'As situações somam as importações.');
        }
        self::assertSame('2026-11-01', $report['totals']['last_applied_on']);
        self::assertSame(1, $report['unknown'], 'O tipo desconhecido fica fora das linhas.');
        $expected = array_fill_keys(array_keys(ImportsOverview::AGES), 0);
        foreach ([2, 74, 20] as $days) {   // 12/11, 01/09 e 25/10 até 14/11/2026
            $expected[ImportsOverview::age($days)]++;
        }
        self::assertSame($expected, $report['pending_by_age']);
        self::assertSame($report['totals']['previewed'], array_sum($report['pending_by_age']));
        self::assertSame([[4, 5, 1], false], [$report['pending_ids'], $report['pending_truncated']], 'Da prévia mais antiga para a mais nova.');
        self::assertSame([[2, 4], false], [$report['with_errors_ids'], $report['with_errors_truncated']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['extrato', 'fulano', 'Fulano', 'abc123hash', 'storage', 'pagador'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= ImportsOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'kind' => self::kinds()[0], 'status' => 'previewed', 'rows_count' => 1, 'error_count' => 1, 'created_at' => '2026-11-01 00:00:00',
                'applied_at' => null];
        }
        $report = ImportsOverview::summarize($many, $this->now);
        self::assertCount(ImportsOverview::MAX_IDS, $report['pending_ids']);
        self::assertTrue($report['pending_truncated']);
        self::assertTrue($report['with_errors_truncated']);
        $empty = ImportsOverview::summarize([], $this->now);
        self::assertSame(count(Imports::KINDS), count($empty['rows']), 'Sem importação, as linhas aparecem zeradas.');
        self::assertNull($empty['totals']['last_applied_on']);
    }

    public function testValidationAndScope(): void
    {
        foreach ([null, '', 0, 'abc', '-1', []] as $value) {
            try {
                $this->service->build($this->user(), $value, $this->now);
                self::fail('Condomínio inválido passou: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, $this->now);
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ImportsOverview.php');
        foreach (['$this->scope->assertAccess(', 'Imports::KINDS', 'SELECT NOW()'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', '->apply(', 'original_name', 'sha256', 'storage_path', 'summary_json', 'created_by', 'applied_by'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem arquivo, caminho, hash, resumo ou quem enviou: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ImportsOverview.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.imports.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/imports", "ImportsOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/users/revoke-condominium-access"'), strpos($index, $route), 'Logo depois do lote de acessos.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /users/revoke-condominium-access:\n"), strpos($openapi, "  /reports/imports:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
