<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\GatehouseTraffic;

/**
 * ADR 0230: movimento da portaria do condomínio no período, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2) para a regra pura (tipos do cadastro, faixas do dia, permanência, saída sem registro, visitas
 * previstas, totais iguais à soma das linhas), a montagem sem dado pessoal, a validação e o escopo; os registros do
 * cadastro são conferidos no MariaDB (Integration\GatehouseTrafficTest).
 */
final class GatehouseTrafficTest extends TestCase
{
    private PDO $pdo;
    private GatehouseTraffic $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_access_logs (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, person_name TEXT, person_document TEXT, kind TEXT, plate TEXT,
                entered_on TEXT, entered_time TEXT, left_time TEXT, gatehouse_id INTEGER, visit_forecast_id INTEGER, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_visit_forecasts (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, visitor_name TEXT, expected_on TEXT, expected_until TEXT,
                status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new GatehouseTraffic($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{kind:string, has_unit:bool, has_plate:bool, from_forecast:bool, entered_time:string, left_time:?string}> */
    private static function october(): array
    {
        $l = static fn (string $kind, bool $unit, bool $plate, bool $forecast, string $in, ?string $out): array => ['kind' => $kind, 'has_unit' => $unit,
            'has_plate' => $plate, 'from_forecast' => $forecast, 'entered_time' => $in, 'left_time' => $out];
        return [
            $l('visitante', true, false, true, '08:30:00', '09:15:00'),    // 45 min, manhã
            $l('visitante', true, true, false, '19:00:00', null),          // sem saída, noite
            $l('visitante', true, false, false, '14:00:00', '13:00:00'),   // saída antes da entrada: saiu, sem permanência
            $l('prestador', true, true, false, '07:00:00', '11:30:00'),    // 270 min
            $l('entregador', true, false, false, '02:10:00', '02:20:00'),  // 10 min, madrugada
        ];
    }

    /** @return list<array{status:string, expected_on:string, expected_until:?string}> */
    private static function planned(): array
    {
        return [
            ['status' => 'done', 'expected_on' => '2026-10-05', 'expected_until' => null],
            ['status' => 'inactive', 'expected_on' => '2026-10-06', 'expected_until' => null],
            ['status' => 'active', 'expected_on' => '2026-10-20', 'expected_until' => null],             // passou sem acontecer
            ['status' => 'active', 'expected_on' => '2026-10-30', 'expected_until' => '2026-11-20'],     // ainda vale
        ];
    }

    public function testSummarizePureRule(): void
    {
        $summary = GatehouseTraffic::summarize(self::october(), self::planned(), '2026-11-11');
        self::assertSame([
            ['visitante', 'Visitante', 3, 1, 3, 1, 2, 1, 1, 45, 45],
            ['prestador', 'Prestador', 1, 1, 1, 0, 1, 0, 1, 270, 270],
            ['entregador', 'Entregador', 1, 0, 1, 0, 1, 0, 1, 10, 10],
            ['morador', 'Morador', 0, 0, 0, 0, 0, 0, 0, 0, null],
        ], array_map(static fn (array $r): array => [$r['kind'], $r['kind_label'], $r['entries'], $r['with_vehicle'], $r['with_unit'], $r['from_forecast'], $r['exited'],
            $r['no_exit'], $r['timed'], $r['stay_minutes_total'], $r['avg_stay_minutes']], $summary['rows']));
        self::assertSame(['madrugada' => 0, 'manha' => 1, 'tarde' => 1, 'noite' => 1], $summary['rows'][0]['by_band']);
        self::assertSame(['entries' => 5, 'with_vehicle' => 2, 'with_unit' => 5, 'from_forecast' => 1, 'exited' => 4, 'no_exit' => 1, 'timed' => 3,
            'stay_minutes_total' => 325, 'avg_stay_minutes' => 108, 'by_band' => ['madrugada' => 1, 'manha' => 2, 'tarde' => 1, 'noite' => 1]], $summary['totals']);
        foreach (['entries', 'with_vehicle', 'with_unit', 'from_forecast', 'exited', 'no_exit', 'timed', 'stay_minutes_total'] as $field) {
            self::assertSame($summary['totals'][$field], array_sum(array_column($summary['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame($summary['totals']['entries'], array_sum($summary['totals']['by_band']), 'Faixas somam as entradas.');
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['entries'], $row['exited'] + $row['no_exit']);
        }
        self::assertSame(['total' => 4, 'done' => 1, 'cancelled' => 1, 'open' => 2, 'overdue' => 1], $summary['forecasts']);
        self::assertSame(['madrugada', 'manha', 'manha', 'tarde', 'noite', 'noite'], array_map([GatehouseTraffic::class, 'band'],
            ['05:59', '06:00', '11:59:59', '12:00', '18:00', '23:59']));
        $empty = GatehouseTraffic::summarize([], [], '2026-11-11');
        self::assertSame([4, 0, null, 0], [count($empty['rows']), $empty['totals']['entries'], $empty['totals']['avg_stay_minutes'], $empty['forecasts']['total']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $insert = $this->pdo->prepare('INSERT INTO erp_access_logs (condominium_id, unit_id, person_name, person_document, kind, plate, entered_on, entered_time, left_time,
            gatehouse_id, visit_forecast_id, notes, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::october() as $i => $l) {
            $insert->execute([1, $l['has_unit'] ? 11 : null, 'Fulano de Tal', '12345678909', $l['kind'], $l['has_plate'] ? 'ABC1D23' : null, '2026-10-0' . ($i + 1),
                $l['entered_time'], $l['left_time'], 4, $l['from_forecast'] ? 9 : null, 'Veio entregar móveis', 'active', null]);
        }
        $insert->execute([1, 11, 'Fulano de Tal', null, 'visitante', '', '2026-10-10', '10:00:00', null, 4, null, null, 'inactive', null]);                // cancelado
        $insert->execute([1, 11, 'Fulano de Tal', null, 'visitante', '', '2026-10-10', '10:00:00', null, 4, null, null, 'active', '2026-10-11 00:00:00']);  // excluído
        $insert->execute([1, 11, 'Fulano de Tal', null, 'visitante', '', '2026-11-01', '10:00:00', null, 4, null, null, 'active', null]);                  // depois do período
        $insert->execute([3, 31, 'Outro', null, 'visitante', '', '2026-10-10', '10:00:00', null, 5, null, null, 'active', null]);                           // outro condomínio
        $forecast = $this->pdo->prepare('INSERT INTO erp_visit_forecasts (condominium_id, unit_id, visitor_name, expected_on, expected_until, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach (self::planned() as $f) {
            $forecast->execute([1, 11, 'Beltrano', $f['expected_on'], $f['expected_until'], $f['status'], null]);
        }
        $forecast->execute([3, 31, 'Outro', '2026-10-05', null, 'active', null]);
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([5, 2, 1, 325], [$report['totals']['entries'], $report['totals']['with_vehicle'], $report['totals']['no_exit'], $report['totals']['stay_minutes_total']]);
        self::assertSame([4, 1, 2], [$report['forecasts']['total'], $report['forecasts']['done'], $report['forecasts']['open']]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Beltrano', '12345678909', 'ABC1D23', 'móveis', 'unit_id', 'gatehouse', 'person', 'plate'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado pessoal: ' . $needle);
        }
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [1, '01/10/2026', '2026-10-31'], [1, '2026-11-01', '2026-10-31'], [1, '2025-01-01', '2026-10-31'],
            [1, '2026-10-01', '2026-02-30']] as [$c, $from, $to]) {
            try {
                $this->service->build($this->user(), $c, $from, $to);
                self::fail('Entrada inválida passou: ' . json_encode([$c, $from, $to]));
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/GatehouseTraffic.php');
        foreach (["RegistryService::definition('access_logs')", '$this->scope->assertAccess(', "deleted_at IS NULL AND status = 'active'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'person_name', 'person_document', 'visitor_name', 'notes', 'SELECT plate', 'SELECT unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/GatehouseTraffic.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.access_logs.view', \$user)", "Access::can('erp.visit_forecasts.view', \$user)",
            'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/gatehouse-traffic", "GatehouseTraffic:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
