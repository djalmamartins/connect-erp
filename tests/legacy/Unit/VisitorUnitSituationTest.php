<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VisitorUnitSituation;

/**
 * ADR 0266: visitantes pela situação da unidade, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (os quatro grupos, a atenção, a concentração, totais iguais à soma), a montagem sem dado pessoal
 * (morador com fim passado, inativo ou excluído contando como unidade vazia; unidade inativa ou excluída), a validação, o
 * escopo e o contrato; os cadastros são conferidos no MariaDB (Integration\VisitorUnitSituationTest).
 */
final class VisitorUnitSituationTest extends TestCase
{
    private PDO $pdo;
    private VisitorUnitSituation $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, document TEXT, phone TEXT,
                permanent_access INTEGER, valid_until TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new VisitorUnitSituation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $v = static fn (?int $unit, string $status, bool $permanent, ?string $until): array => ['unit_id' => $unit, 'status' => $status,
            'permanent_access' => $permanent, 'valid_until' => $until];
        $visitors = [$v(1, 'active', true, null), $v(1, 'active', false, '2027-01-01'), $v(1, 'inactive', true, null),   // 1: com morador
            $v(2, 'active', true, null), $v(2, 'active', false, '2026-01-01'), $v(2, 'active', false, null), $v(2, 'inactive', false, null), // 2: vazia
            $v(3, 'active', true, null),                                                                                     // 3: inativa
            $v(null, 'active', true, null), $v(null, 'inactive', false, null)];                                              // sem vínculo
        $summary = VisitorUnitSituation::summarize($visitors, [1 => true], [1 => true, 2 => true]);
        self::assertSame([['inhabited', 1, 3, 2, 1, 1], ['empty', 1, 4, 3, 1, 1], ['inactive_unit', 1, 1, 1, 1, 0], ['unlinked', 0, 2, 1, 1, 1]],
            array_map(static fn (array $r): array => [$r['group'], $r['units'], $r['visitors'], $r['active'], $r['permanent'], $r['blocked']], $summary['rows']));
        foreach (['units', 'visitors', 'active', 'permanent', 'blocked'] as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['visitors'], $row['active'] + $row['blocked']);
        }
        self::assertSame(['empty_active' => 3, 'blockable' => 2, 'incomplete' => 1], $summary['attention'], 'A data vencida também é bloqueável.');
        self::assertSame($summary['rows'][1]['active'], $summary['attention']['empty_active']);
        self::assertSame(['um' => 1, 'de_2_a_5' => 2, 'mais_de_5' => 0, 'units_with_active' => 3], $summary['concentration']);
        self::assertSame(['active' => 'Liberado', 'blocked' => 'Bloqueado'], $summary['status_labels']);

        self::assertSame('inhabited', VisitorUnitSituation::classify(5, true, false), 'Morador ativo vem antes da unidade inativa, como no bloqueio em lote.');
        self::assertSame('unlinked', VisitorUnitSituation::classify(null, false, false));
        $many = VisitorUnitSituation::summarize(array_fill(0, 6, $v(9, 'active', false, '2027-01-01')), [], [9 => true]);
        self::assertSame([1, 1, 6], [$many['concentration']['mais_de_5'], $many['concentration']['units_with_active'], $many['attention']['blockable']]);
        $empty = VisitorUnitSituation::summarize([], [], []);
        self::assertSame([4, 0, 0, 0], [count($empty['rows']), $empty['totals']['visitors'], $empty['attention']['empty_active'], $empty['concentration']['units_with_active']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $past = date('Y-m-d', strtotime('-30 days'));
        $future = date('Y-m-d', strtotime('+30 days'));
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, status, deleted_at) VALUES (40, 1, 'active', NULL), (41, 1, 'active', NULL), (42, 1, 'active', NULL),
            (43, 1, 'inactive', NULL), (44, 1, 'active', NULL), (45, 1, 'active', '2026-01-01 00:00:00'), (50, 3, 'active', NULL)");
        // 40: só morador excluído; 41: com morador ativo; 42: só morador com fim passado; 43: inativa; 44: só morador inativo; 45: excluída.
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id, unit_id, name, ends_on, status, deleted_at) VALUES (1, 41, 'Morador Beltrano', '{$future}', 'active', NULL),
            (1, 42, 'Saiu', '{$past}', 'active', NULL), (1, 44, 'Inativo', NULL, 'inactive', NULL), (1, 40, 'Excluído', NULL, 'active', '2026-01-01 00:00:00'),
            (3, 50, 'De fora', NULL, 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_visitors (id, condominium_id, unit_id, unit_label, name, document, phone, permanent_access, valid_until, notes, status, deleted_at) VALUES
            (1, 1, 40, 'Apto 101', 'Diarista Fulana', '12345678909', '11988887777', 1, NULL, 'Às terças', 'active', NULL),
            (2, 1, 42, 'Apto 102', 'Personal', NULL, NULL, 0, '{$future}', NULL, 'active', NULL), (3, 1, 41, 'Apto 103', 'Com morador', NULL, NULL, 1, NULL, NULL, 'active', NULL),
            (4, 1, NULL, 'Apto 104', 'Só texto', NULL, NULL, 1, NULL, NULL, 'active', NULL), (5, 1, 43, 'Apto 105', 'Unidade inativa', NULL, NULL, 1, NULL, NULL, 'active', NULL),
            (6, 1, 44, 'Apto 106', 'Sem data', NULL, NULL, 0, NULL, NULL, 'active', NULL), (7, 1, 40, 'Apto 101', 'Já bloqueado', NULL, NULL, 1, NULL, NULL, 'inactive', NULL),
            (8, 3, 50, 'Apto 1', 'De fora', NULL, NULL, 1, NULL, NULL, 'active', NULL), (9, 1, 40, 'Apto 101', 'Excluído', NULL, NULL, 1, NULL, NULL, 'active', '2026-01-01 00:00:00'),
            (10, 1, 41, 'Apto 103', 'Outro', NULL, NULL, 0, '{$future} 00:00:00', NULL, 'active', NULL), (11, 1, 45, 'Apto 107', 'Excluída', NULL, NULL, 0, NULL, NULL, 'inactive', NULL)");
        $report = $this->service->build($this->user(), '1');
        self::assertSame([1, date('Y-m-d')], [$report['condominium_id'], $report['on']]);
        self::assertSame([['inhabited', 1, 2, 2, 1, 0], ['empty', 3, 4, 3, 1, 1], ['inactive_unit', 2, 2, 1, 1, 1], ['unlinked', 0, 1, 1, 1, 0]],
            array_map(static fn (array $r): array => [$r['group'], $r['units'], $r['visitors'], $r['active'], $r['permanent'], $r['blocked']], $report['rows']));
        self::assertSame(['units' => 6, 'visitors' => 9, 'active' => 7, 'permanent' => 4, 'blocked' => 2], $report['totals']);
        self::assertSame(['empty_active' => 3, 'blockable' => 2, 'incomplete' => 1], $report['attention']);
        self::assertSame(['um' => 4, 'de_2_a_5' => 1, 'mais_de_5' => 0, 'units_with_active' => 5], $report['concentration']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulana', 'Beltrano', '12345678909', '11988887777', 'Apto', 'terças', 'unit_id', 'unit_label', 'document', 'phone', '"40"', ':40'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([null, '', 'x', 0, '-1', 1.5, []] as $condominium) {
            try {
                $this->service->build($this->user(), $condominium);
                self::fail('Entrada inválida passou: ' . json_encode($condominium));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->build($this->user(), 3);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VisitorUnitSituation.php');
        foreach (["RegistryService::definition('visitors')", '$this->scope->assertAccess(', 'deleted_at IS NULL', '(ends_on IS NULL OR ends_on >= ?)'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', 'document', 'phone', 'unit_label', 'notes', 'NotificationOutbox', 'mail_queue'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VisitorUnitSituation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.visitors.view', \$user)", "Access::can('erp.residents.view', \$user)",
            'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/visitor-units", "VisitorUnitSituation:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/visitors/block-unattached"'), strpos($index, $route), 'Logo depois do bloqueio em lote.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
