<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\VacantUnits;

/**
 * ADR 0270: unidades vagas e o que ficou nelas, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2) para a regra pura (as faixas da vaga com as bordas, as três linhas, unidades sem contar duas vezes, a dívida só das
 * vagas, totais iguais à soma), a montagem sem dado pessoal (morador ativo, com fim passado, inativo e excluído; registros
 * inativos, excluídos e de unidade inativa de fora; conta paga e a vencer de fora), a validação, o escopo e o contrato; os
 * cadastros são conferidos no MariaDB (Integration\VacantUnitsTest).
 */
final class VacantUnitsTest extends TestCase
{
    private PDO $pdo;
    private VacantUnits $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, ends_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_vehicles (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, plate TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_pets (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_entries (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, nature TEXT, amount_cents INTEGER, due_on TEXT, paid_on TEXT,
                status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new VacantUnits($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testAgeBucketAndSummarizePureRules(): void
    {
        $on = '2026-11-14';
        self::assertSame('never_linked', VacantUnits::ageBucket(false, null, $on));
        self::assertSame('unknown_date', VacantUnits::ageBucket(true, null, $on));
        self::assertSame('ate_30', VacantUnits::ageBucket(true, '2026-10-15', $on), '30 dias ainda é até 30.');
        self::assertSame('de_31_a_180', VacantUnits::ageBucket(true, '2026-10-14', $on));
        self::assertSame('de_31_a_180', VacantUnits::ageBucket(true, '2026-05-18', $on), '180 dias ainda é até 180.');
        self::assertSame('mais_de_180', VacantUnits::ageBucket(true, '2026-05-17', $on));
        self::assertSame('ate_30', VacantUnits::ageBucket(true, '2026-12-01 00:00:00', $on), 'Fim futuro (vínculo inativo) conta como recente.');

        $summary = VacantUnits::summarize([1, 2, 3, 4], [1 => true], [2 => ['ever' => true, 'last_end' => '2026-11-01']],
            ['visitors' => [1 => 5, 2 => 2], 'vehicles' => [2 => 1], 'pets' => [3 => 1]], [1 => ['entries' => 9, 'amount_cents' => 900], 3 => ['entries' => 2, 'amount_cents' => 500]], $on);
        self::assertSame(['active' => 4, 'occupied' => 1, 'vacant' => 3, 'with_leftovers' => 2, 'clean' => 1], $summary['units']);
        self::assertSame([[2, 0, 1, 0, 0], 3], [array_column($summary['vacancy'], 'units'), array_sum(array_column($summary['vacancy'], 'units'))]);
        self::assertSame([['visitors', 1, 2], ['vehicles', 1, 1], ['pets', 1, 1]],
            array_map(static fn (array $r): array => [$r['registry'], $r['units'], $r['records']], $summary['rows']), 'A unidade ocupada não conta.');
        self::assertSame(['records' => 4], $summary['totals']);
        self::assertSame($summary['totals']['records'], array_sum(array_column($summary['rows'], 'records')));
        self::assertSame(['units' => 1, 'entries' => 2, 'amount_cents' => 500], $summary['debt'], 'Só a dívida das vagas.');
        $empty = VacantUnits::summarize([], [], [], [], [], $on);
        self::assertSame([3, count(VacantUnits::VACANCY), 0, 0], [count($empty['rows']), count($empty['vacancy']), $empty['units']['vacant'], $empty['totals']['records']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $d = static fn (int $days): string => date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days'));
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (40, 1, 'Apto 40', 'active', NULL), (41, 1, 'Apto 41', 'active', NULL),
            (42, 1, 'Apto 42', 'active', NULL), (43, 1, 'Apto 43', 'active', NULL), (44, 1, 'Apto 44', 'active', NULL), (45, 1, 'Apto 45', 'active', NULL),
            (46, 1, 'Apto 46', 'active', NULL), (47, 1, 'Apto 47', 'inactive', NULL), (48, 1, 'Apto 48', 'active', '2026-01-01 00:00:00'), (50, 3, 'Fora', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id, unit_id, name, ends_on, status, deleted_at) VALUES
            (1, 40, 'Fulana Moradora', NULL, 'active', NULL), (1, 41, 'Saiu Agora', '{$d(-10)}', 'active', NULL), (1, 42, 'Saiu Antes', '{$d(-100)}', 'inactive', NULL),
            (1, 44, 'Sem data', NULL, 'inactive', NULL), (1, 45, 'Saiu Faz Tempo', '{$d(-400)}', 'inactive', NULL), (1, 46, 'Excluído', NULL, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_visitors (condominium_id, unit_id, name, status, deleted_at) VALUES (1, 40, 'Visita ocupada', 'active', NULL),
            (1, 41, 'Diarista', 'active', NULL), (1, 41, 'Personal', 'active', NULL), (1, 45, 'Excluída', 'active', '2026-01-01 00:00:00'), (1, 47, 'Inativa', 'active', NULL),
            (1, 44, 'Bloqueado', 'inactive', NULL), (3, 50, 'Fora', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_vehicles (condominium_id, unit_id, plate, status, deleted_at) VALUES (1, 41, 'QWE9R87', 'active', NULL),
            (1, 43, 'ASD5F67', 'inactive', NULL), (1, NULL, 'ZXC1V23', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_pets (condominium_id, unit_id, name, status, deleted_at) VALUES (1, 42, 'Rex', 'active', NULL), (1, 46, 'Mimi', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_entries (condominium_id, unit_id, nature, amount_cents, due_on, paid_on, status, deleted_at) VALUES
            (1, 42, 'receita', 10000, '{$d(-40)}', NULL, 'active', NULL), (1, 42, 'receita', 5000, '{$d(-10)}', NULL, 'active', NULL),
            (1, 42, 'despesa', 7000, '{$d(-10)}', NULL, 'active', NULL), (1, 45, 'receita', 3000, '{$d(-10)}', '{$d(-5)}', 'active', NULL),
            (1, 45, 'receita', 3000, '{$d(10)}', NULL, 'active', NULL), (1, 45, 'receita', 3000, '{$d(-10)}', NULL, 'inactive', NULL),
            (1, 40, 'receita', 99900, '{$d(-10)}', NULL, 'active', NULL), (3, 50, 'receita', 1000, '{$d(-10)}', NULL, 'active', NULL)");
        $report = $this->service->build($this->user(), '1');
        self::assertSame(['active' => 7, 'occupied' => 1, 'vacant' => 6, 'with_leftovers' => 3, 'clean' => 3], $report['units']);
        self::assertSame(['never_linked' => 2, 'unknown_date' => 1, 'ate_30' => 1, 'de_31_a_180' => 1, 'mais_de_180' => 1],
            array_combine(array_column($report['vacancy'], 'bucket'), array_column($report['vacancy'], 'units')));
        self::assertSame([['visitors', 1, 2], ['vehicles', 1, 1], ['pets', 2, 2]],
            array_map(static fn (array $r): array => [$r['registry'], $r['units'], $r['records']], $report['rows']));
        self::assertSame(['records' => 5], $report['totals']);
        self::assertSame(['units' => 1, 'entries' => 2, 'amount_cents' => 15000], $report['debt']);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulana', 'Diarista', 'Rex', 'QWE9R87', 'Apto', 'unit_id', '"41"', '"label"'] as $needle) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/VacantUnits.php');
        foreach (['$this->scope->assertAccess(', 'deleted_at IS NULL', '(ends_on IS NULL OR ends_on >= ?)', "nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on < ?"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'SELECT name', 'plate', 'label FROM', 'document', 'NotificationOutbox', 'mail_queue'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VacantUnits.php');
        foreach (["'erp.access'", "'erp.units.view'", "'erp.residents.view'", "'erp.visitors.view'", "'erp.vehicles.view'", "'erp.pets.view'", "'erp.entries.view'",
            'Access::can($permission, $user)', 'catch (CondominiumAccessDenied)', 'catch (InvalidArgumentException'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/vacant-units", "VacantUnits:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/pets/deactivate-unattached"'), strpos($index, $route), 'Logo depois da inativação dos animais.');
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
