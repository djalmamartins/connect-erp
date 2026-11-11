<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommonAreaUsage;

/**
 * ADR 0228: uso dos espaços comuns do condomínio no período, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2) para a regra pura (situações, taxa só das confirmadas, dias e unidades distintos, dia da semana, áreas
 * sem reserva, totais iguais à soma das linhas), a montagem sem dado pessoal, a validação e o escopo; as reservas do
 * cadastro são conferidas no MariaDB (Integration\CommonAreaUsageTest).
 */
final class CommonAreaUsageTest extends TestCase
{
    private PDO $pdo;
    private CommonAreaUsage $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, booking_mode TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT,
                reserved_on TEXT, starts_time TEXT, notes TEXT, fee_cents INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new CommonAreaUsage($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return list<array{id:int, name:string, booking_mode:string, status:string}> */
    private static function areas(): array
    {
        return [
            ['id' => 1, 'name' => 'Salão de festas', 'booking_mode' => 'approval', 'status' => 'active'],
            ['id' => 2, 'name' => 'Churrasqueira', 'booking_mode' => 'free', 'status' => 'active'],
            ['id' => 3, 'name' => 'Quadra antiga', 'booking_mode' => 'none', 'status' => 'inactive'],   // inativa com reserva: entra
            ['id' => 4, 'name' => 'Sauna', 'booking_mode' => 'blocked', 'status' => 'inactive'],        // inativa sem reserva: fica de fora
            ['id' => 5, 'name' => 'Academia', 'booking_mode' => 'free', 'status' => 'active'],          // ativa sem reserva: entra zerada
        ];
    }

    /** @return list<array{common_area_id:int, unit_id:int, reserved_on:string, status:string, fee_cents:?int}> */
    private static function october(): array
    {
        $r = static fn (int $area, int $unit, string $on, string $status, ?int $fee): array => ['common_area_id' => $area, 'unit_id' => $unit,
            'reserved_on' => $on, 'status' => $status, 'fee_cents' => $fee];
        return [
            $r(1, 101, '2026-10-03', 'confirmed', 15000), $r(1, 102, '2026-10-03', 'confirmed', 15000),   // sábado, mesmo dia
            $r(1, 101, '2026-10-10', 'confirmed', null), $r(1, 103, '2026-10-12', 'inactive', 15000), $r(1, 104, '2026-10-20', 'active', 15000),
            $r(2, 101, '2026-10-04', 'confirmed', 5000), $r(2, 103, '2026-10-11', 'confirmed', 5000), $r(2, 104, '2026-10-15', 'confirmed', 5000),
            $r(2, 101, '2026-10-18', 'inactive', 5000),
            $r(3, 102, '2026-10-07', 'confirmed', null),
        ];
    }

    public function testSummarizePureRule(): void
    {
        $summary = CommonAreaUsage::summarize(self::areas(), self::october(), '2026-10-01', '2026-10-31');
        self::assertSame(31, $summary['period_days']);
        self::assertSame([
            ['Churrasqueira', 'Livre', true, 4, 3, 0, 1, 15000, 3, 9.7, 3, 25.0],
            ['Salão de festas', 'Exige aprovação', true, 5, 3, 1, 1, 30000, 2, 6.5, 2, 20.0],
            ['Quadra antiga', 'Não reservável', false, 1, 1, 0, 0, 0, 1, 3.2, 1, 0.0],
            ['Academia', 'Livre', true, 0, 0, 0, 0, 0, 0, 0.0, 0, null],
        ], array_map(static fn (array $r): array => [$r['name'], $r['booking_mode_label'], $r['area_active'], $r['reservations'], $r['confirmed'], $r['pending'],
            $r['cancelled'], $r['fee_cents'], $r['days_used'], $r['occupancy_pct'], $r['units'], $r['cancel_pct']], $summary['rows']));
        self::assertSame(['mon' => 0, 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 3, 'sun' => 0], $summary['rows'][1]['by_weekday']);
        self::assertSame(['areas' => 4, 'reservations' => 10, 'confirmed' => 7, 'pending' => 1, 'cancelled' => 2, 'fee_cents' => 45000, 'days_used' => 6,
            'cancel_pct' => 20.0, 'by_weekday' => ['mon' => 0, 'tue' => 0, 'wed' => 1, 'thu' => 1, 'fri' => 0, 'sat' => 3, 'sun' => 2]], $summary['totals']);
        foreach (['reservations', 'confirmed', 'pending', 'cancelled', 'fee_cents', 'days_used'] as $field) {
            self::assertSame($summary['totals'][$field], array_sum(array_column($summary['rows'], $field)), 'Total = soma das linhas: ' . $field);
        }
        self::assertSame($summary['totals']['confirmed'], array_sum($summary['totals']['by_weekday']), 'Dias da semana somam as confirmadas.');
        foreach ($summary['rows'] as $row) {
            self::assertSame($row['reservations'], $row['confirmed'] + $row['pending'] + $row['cancelled']);
        }
        $empty = CommonAreaUsage::summarize([], [], '2026-10-01', '2026-10-01');
        self::assertSame([1, [], 0, null], [$empty['period_days'], $empty['rows'], $empty['totals']['reservations'], $empty['totals']['cancel_pct']]);
    }

    public function testBuildWithoutPersonalData(): void
    {
        $area = $this->pdo->prepare('INSERT INTO erp_common_areas (id, condominium_id, name, booking_mode, status, deleted_at) VALUES (?, ?, ?, ?, ?, ?)');
        foreach (self::areas() as $a) {
            $area->execute([$a['id'], 1, $a['name'], $a['booking_mode'], $a['status'], null]);
        }
        $area->execute([6, 1, 'Excluída', 'free', 'active', '2026-09-01 00:00:00']);
        $area->execute([7, 3, 'De outro', 'free', 'active', null]);
        $insert = $this->pdo->prepare('INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, starts_time, notes, fee_cents, status,
            deleted_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        foreach (self::october() as $r) {
            $insert->execute([1, $r['common_area_id'], $r['unit_id'], 'Fulano de Tal', $r['reserved_on'], '18:00:00', 'Aniversário', $r['fee_cents'], $r['status'], null]);
        }
        $insert->execute([1, 1, 101, 'Fulano de Tal', '2026-10-05', '18:00:00', null, 15000, 'confirmed', '2026-10-02 00:00:00']);   // excluída
        $insert->execute([1, 1, 101, 'Fulano de Tal', '2026-11-01', '18:00:00', null, 15000, 'confirmed', null]);                    // depois do período
        $insert->execute([3, 7, 900, 'Outro', '2026-10-05', '18:00:00', null, 15000, 'confirmed', null]);                           // outro condomínio
        $report = $this->service->build($this->user(), '1', '2026-10-01', '2026-10-31');
        self::assertSame([4, 10, 7, 45000], [$report['totals']['areas'], $report['totals']['reservations'], $report['totals']['confirmed'], $report['totals']['fee_cents']]);
        self::assertSame(['Churrasqueira', 'Salão de festas', 'Quadra antiga', 'Academia'], array_column($report['rows'], 'name'));
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Aniversário', '18:00', 'resident', 'unit_id', '"101"', ':101', 'Excluída', 'De outro'] as $needle) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/CommonAreaUsage.php');
        foreach (["Definitions::get('common_areas')", '$this->scope->assertAccess(', 'deleted_at IS NULL'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'resident_name', 'notes', 'starts_time', 'ends_time', 'unit_label', 'erp_units'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CommonAreaUsage.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.reservations.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/common-area-usage", "CommonAreaUsage:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
