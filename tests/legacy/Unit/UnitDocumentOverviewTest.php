<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\UnitDocumentOverview;
use Source\Services\Erp\UnitDocuments;

/**
 * ADR 0292: documentos emitidos para as unidades, por tipo e mês a mês, somente leitura. Unit com SQLite (Pdo\Sqlite
 * quando existe; PDO no PHP 8.2) para a regra pura (linhas por tipo com totais iguais à soma, resultado somando os
 * emitidos, meses somando os emitidos, cancelados à parte e ids com o corte), a montagem sem código, conteúdo nem unidade
 * (fora da janela, outro condomínio e unidade de outro condomínio de fora), a validação, o escopo e o contrato. Os tipos vêm
 * de UnitDocuments::ALL_KINDS e batem com a enum da migration; nada de quantidade nem lista fixa. O MariaDB confere com
 * documentos de verdade (Integration\UnitDocumentOverviewTest).
 */
final class UnitDocumentOverviewTest extends TestCase
{
    private PDO $pdo;
    private UnitDocumentOverview $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT)',
            'CREATE TABLE erp_unit_documents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, kind TEXT, reference TEXT, result TEXT, total_cents INTEGER,
                payload_json TEXT, verification_code TEXT, status TEXT, issued_by INTEGER, cancel_reason TEXT, created_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        [$a, $b] = self::kinds();
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label) VALUES (5, 1, 'Apto 101'), (6, 1, 'Apto 102'), (9, 3, 'Apto 901')");
        $insert = $this->pdo->prepare("INSERT INTO erp_unit_documents (id, condominium_id, unit_id, kind, reference, result, total_cents, payload_json, verification_code, status,
            issued_by, cancel_reason, created_at) VALUES (?, ?, ?, ?, 'Venda do Fulano', ?, ?, '{\"morador\":\"Fulano\"}', ?, ?, 3, 'Erro do Fulano', ?)");
        foreach ([[1, 1, 5, $a, 'debts', 1500, 'ABCD1111EFGH2222', 'issued', '2026-11-02 10:00:00'], [2, 1, 6, $a, 'clear', 0, 'ABCD1111EFGH3333', 'issued', '2026-11-03 10:00:00'],
            [3, 1, 5, $a, 'debts', 500, 'ABCD1111EFGH4444', 'issued', '2026-10-05 10:00:00'], [4, 1, 5, $a, 'debts', 900, 'ABCD1111EFGH5555', 'cancelled', '2026-10-06 10:00:00'],
            [5, 1, 6, $b, 'clear', 0, 'ABCD1111EFGH6666', 'issued', '2026-09-01 00:00:00'], [6, 1, 6, $b, 'clear', 0, 'ABCD1111EFGH7777', 'cancelled', '2026-11-10 10:00:00'],
            [7, 1, 5, $b, 'clear', 0, 'ABCD1111EFGH8888', 'issued', '2026-08-31 23:59:59'], [8, 3, 9, $a, 'debts', 99, 'ABCD1111EFGH9999', 'issued', '2026-11-01 10:00:00'],
            [9, 1, 9, $a, 'debts', 77, 'ABCD1111EFGHAAAA', 'issued', '2026-11-01 10:00:00']] as $row) {
            $insert->execute($row);
        }
        $this->service = new UnitDocumentOverview($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos de UnitDocuments::ALL_KINDS */
    private static function kinds(): array
    {
        $kinds = array_keys(UnitDocuments::ALL_KINDS);
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[count($kinds) - 1]];
    }

    public function testKindsMatchTheMigrationEnum(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0534_entry_shortcuts.sql');   // a última que muda a enum
        preg_match('/ALTER TABLE `erp_unit_documents`\s+MODIFY `kind` enum\(([^)]*)\)/', $sql, $kind);
        self::assertSame(array_keys(UnitDocuments::ALL_KINDS), array_map('trim', explode(',', str_replace("'", '', $kind[1]))));
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$a, $b] = self::kinds();
        $report = $this->service->build($this->user(), '1', '3', '2026-11-14');
        self::assertSame([1, 3, '2026-09', '2026-11'], [$report['condominium_id'], $report['months'], $report['from'], $report['to']]);
        self::assertSame(array_keys(UnitDocuments::ALL_KINDS), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        $pick = static fn (array $r): array => [$r['issued'], $r['cancelled'], $r['clear'], $r['debts'], $r['debts_cents'], $r['units']];
        self::assertSame([3, 1, 1, 2, 2000, 2], $pick($rows[$a]), 'Sem o de outro condomínio nem o de unidade de outro condomínio.');
        self::assertSame([1, 1, 1, 0, 0, 1], $pick($rows[$b]), 'Sem o de fora da janela.');
        foreach (array_keys($report['totals']) as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['issued'], $row['clear'] + $row['debts'], 'O resultado soma os emitidos.');
        }
        self::assertSame([['2026-09', 1], ['2026-10', 1], ['2026-11', 2]], array_map(static fn (array $m): array => [$m['month'], $m['issued']], $report['by_month']));
        self::assertSame($report['totals']['issued'], array_sum(array_column($report['by_month'], 'issued')), 'Os meses somam os emitidos.');
        self::assertSame([[6, 4], false, 0], [$report['cancelled_ids'], $report['cancelled_truncated'], $report['unknown']], 'Do cancelado mais recente para o mais antigo.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Venda', 'Apto', 'ABCD1111', 'verification', 'payload', 'reference', 'unit_id', 'issued_by'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutUnknownAndEmpty(): void
    {
        $many = [];
        for ($i = 1; $i <= UnitDocumentOverview::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'kind' => self::kinds()[0], 'unit_id' => 1, 'month' => '2026-11', 'status' => 'cancelled', 'result' => 'clear', 'total_cents' => 0,
                'created_at' => sprintf('2026-11-01 10:%02d:%02d', intdiv($i, 60), $i % 60)];
        }
        $many[] = ['id' => 999, 'kind' => 'tipo_novo', 'unit_id' => 1, 'month' => '2026-11', 'status' => 'issued', 'result' => 'clear', 'total_cents' => 0,
            'created_at' => '2026-11-01 10:00:00'];
        $report = UnitDocumentOverview::summarize($many, ['2026-11']);
        self::assertCount(UnitDocumentOverview::MAX_IDS, $report['cancelled_ids']);
        self::assertTrue($report['cancelled_truncated']);
        self::assertSame([UnitDocumentOverview::MAX_IDS + 1, 1], [$report['totals']['cancelled'], $report['unknown']], 'A contagem não é cortada; o tipo desconhecido fica de fora.');
        $empty = UnitDocumentOverview::summarize([], ['2026-10', '2026-11']);
        self::assertSame([count(UnitDocuments::ALL_KINDS), 2, 0], [count($empty['rows']), count($empty['by_month']), $empty['totals']['issued']]);
    }

    public function testValidationAndScope(): void
    {
        foreach ([[null, null], ['', null], [0, null], ['abc', null], [1, '0'], [1, 'x'], [1, (string)(UnitDocumentOverview::MAX_MONTHS + 1)], [[], null]] as [$c, $m]) {
            try {
                $this->service->build($this->user(), $c, $m, '2026-11-14');
                self::fail('Entrada inválida passou: ' . json_encode([$c, $m]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/UnitDocumentOverview.php');
        foreach (['$this->scope->assertAccess(', 'UnitDocuments::ALL_KINDS', 'DiscountOverview::months(', 'SELECT NOW()', 'u.condominium_id = d.condominium_id'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'verification_code', 'payload_json', 'reference', 'cancel_reason', 'issued_by', 'u.label'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem código, conteúdo, finalidade, motivo, quem emitiu ou unidade: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/UnitDocumentOverview.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.unit_documents.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $list = (string)file_get_contents($root . '/source/Controllers/Api/V1/UnitDocuments.php');
        self::assertStringContainsString("Access::can('erp.unit_documents.view', \$user)", $list, 'A mesma permissão da lista de documentos.');
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/unit-documents", "UnitDocumentOverview:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/assemblies/cancel-stale-drafts"'), strpos($index, $route), 'Logo depois do lote de rascunhos.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /assemblies/cancel-stale-drafts:\n"), strpos($openapi, "  /reports/unit-documents:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
