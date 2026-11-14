<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\WaitlistDemand;

/**
 * ADR 0260: procura pelas áreas comuns na lista de espera. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para
 * a regra pura (aguardando antes e depois de hoje, chamadas, atendidas, desistências, unidades e datas diferentes, próxima
 * data, área excluída; total = soma das linhas), a consulta sem dado do morador, a validação do período, o escopo e o
 * contrato; as inscrições gravadas pelo cadastro são conferidas no MariaDB (Integration\WaitlistDemandTest).
 */
final class WaitlistDemandTest extends TestCase
{
    private PDO $pdo;
    private WaitlistDemand $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_waitlist (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT, desired_on TEXT,
                notes TEXT, called_at TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->service = new WaitlistDemand($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testSummarizePureRule(): void
    {
        $e = static fn (int $area, int $unit, string $on, string $status = 'active', bool $called = false): array => ['area_id' => $area, 'unit_id' => $unit,
            'desired_on' => $on, 'status' => $status, 'called' => $called];
        $summary = WaitlistDemand::summarize([['id' => 1, 'name' => 'Salão', 'status' => 'active'], ['id' => 2, 'name' => 'Quadra', 'status' => 'active'],
            ['id' => 3, 'name' => 'Piscina', 'status' => 'inactive']], [$e(1, 10, '2026-11-20', 'active', true), $e(1, 11, '2026-11-20'), $e(1, 10, '2026-12-01'),
            $e(1, 12, '2026-11-01'), $e(1, 11, '2026-10-01', 'served'), $e(2, 10, '2026-11-14'), $e(2, 13, '2026-09-01', 'inactive'), $e(99, 10, '2026-11-30')], '2026-11-14');
        self::assertSame([1, 2, 3, null], array_column($summary['rows'], 'area_id'), 'As áreas do cadastro e a linha da área excluída.');
        self::assertSame([3, 1, 0, 1], array_column($summary['rows'], 'waiting_upcoming'), 'Hoje conta como próxima.');
        self::assertSame([1, 0, 0, 0], array_column($summary['rows'], 'waiting_past'));
        self::assertSame([1, 0, 0, 0], array_column($summary['rows'], 'called'));
        self::assertSame([1, 0, 0, 0], array_column($summary['rows'], 'served'));
        self::assertSame([0, 1, 0, 0], array_column($summary['rows'], 'gave_up'));
        self::assertSame([5, 2, 0, 1], array_column($summary['rows'], 'entries'));
        self::assertSame([3, 2, 0, 1], array_column($summary['rows'], 'units'), 'Unidades diferentes por área.');
        self::assertSame([2, 1, 0, 1], array_column($summary['rows'], 'dates_upcoming'), 'Datas diferentes com alguém aguardando.');
        self::assertSame(['2026-11-20', '2026-11-14', null, '2026-11-30'], array_column($summary['rows'], 'next_desired_on'));
        foreach (WaitlistDemand::KEYS as $key) {
            self::assertSame($summary['totals'][$key], array_sum(array_column($summary['rows'], $key)), 'Total = soma das linhas: ' . $key);
        }
        self::assertSame(['Área excluída', 'deleted'], [$summary['rows'][3]['area_name'], $summary['rows'][3]['area_status']]);
        self::assertSame([], WaitlistDemand::summarize([], [], '2026-11-14')['rows']);
    }

    public function testBuildWithoutResidentData(): void
    {
        $today = date('Y-m-d');
        $soon = date('Y-m-d', strtotime('+5 days'));
        $this->pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name, status, deleted_at) VALUES (1, 1, 'Salão de festas', 'active', NULL),
            (2, 1, 'Excluída', 'active', '2026-01-01 00:00:00'), (3, 3, 'De fora', 'active', NULL)");
        $insert = $this->pdo->prepare('INSERT INTO erp_waitlist (condominium_id, common_area_id, unit_id, resident_name, desired_on, notes, called_at, status, deleted_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([1, 1, 40, 'Fulano de Tal', $soon, 'Aniversário', '2026-11-01 10:00:00', 'active', null]);
        $insert->execute([1, 1, 41, 'Beltrana', $today, null, null, 'active', null]);
        $insert->execute([1, 2, 40, 'Na excluída', $soon, null, null, 'active', null]);
        $insert->execute([3, 3, 50, 'De fora', $soon, null, null, 'active', null]);
        $insert->execute([1, 1, 42, 'Excluída', $soon, null, null, 'active', '2026-01-01 00:00:00']);
        $insert->execute([1, 1, 43, 'Fora do período', '2020-01-01', null, null, 'active', null]);
        $report = $this->service->build($this->user(), '1');
        self::assertSame([1, $today, 3, 1, 2, [1, null]], [$report['condominium_id'], $report['today'], $report['totals']['waiting_upcoming'], $report['totals']['called'],
            $report['rows'][0]['dates_upcoming'], array_column($report['rows'], 'area_id')]);
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Beltrana', 'Aniversário', 'resident', 'notes', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do morador: ' . $needle);
        }
        $narrow = $this->service->build($this->user(), 1, $today, $today);
        self::assertSame([1, $today, $today], [$narrow['totals']['entries'], $narrow['from'], $narrow['to']], 'Só o período pedido.');
    }

    public function testValidationScopeAndContract(): void
    {
        foreach ([[null, null, null], ['x', null, null], [0, null, null], [1, '2026-02-30', null], [1, null, '14/11/2026'], [1, '2026-12-01', '2026-11-01'],
            [1, '2020-01-01', '2026-11-14'], [1, ['x'], null]] as [$c, $from, $to]) {
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/WaitlistDemand.php');
        foreach (['$this->scope->assertAccess(', 'FROM erp_common_areas WHERE condominium_id = ? AND deleted_at IS NULL',
            'FROM erp_waitlist' . "\n" . '            WHERE condominium_id = ? AND deleted_at IS NULL AND desired_on BETWEEN ? AND ?'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'resident_name', 'notes'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura e sem dado do morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/WaitlistDemand.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.reservations.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->get("/reports/waitlist-demand", "WaitlistDemand:show", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
