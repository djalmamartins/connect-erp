<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CommonAreaBookingMode;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0229: bloqueio e liberação em lote das áreas comuns. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o
 * PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário e o contrato
 * de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB (Integration\CommonAreaBookingModeTest).
 */
final class CommonAreaBookingModeTest extends TestCase
{
    private PDO $pdo;
    private CommonAreaBookingMode $service;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class)
            ? new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            }
            : new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, booking_mode TEXT, capacity INTEGER, booking_fee_cents INTEGER,
                min_advance_days INTEGER, max_advance_days INTEGER, opening_time TEXT, closing_time TEXT, rules TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT,
                reserved_on TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name, booking_mode, status) VALUES
            (1, 1, 'Salão de festas', 'approval', 'active'), (2, 1, 'Churrasqueira', 'free', 'active'), (3, 1, 'Quadra', 'blocked', 'active'),
            (4, 1, 'Sauna', 'approval', 'inactive'), (5, 3, 'De outro', 'free', 'active')");
        $ahead = date('Y-m-d', strtotime('+10 days'));
        $past = date('Y-m-d', strtotime('-10 days'));
        $this->pdo->exec("INSERT INTO erp_reservations (condominium_id, common_area_id, unit_id, resident_name, reserved_on, status, deleted_at) VALUES
            (1, 1, 11, 'Fulano', '{$ahead}', 'confirmed', NULL), (1, 1, 12, 'Beltrano', '{$ahead}', 'active', NULL),
            (1, 2, 11, 'Fulano', '{$past}', 'confirmed', NULL), (1, 2, 12, 'Beltrano', '{$ahead}', 'inactive', NULL),
            (1, 2, 13, 'Ciclano', '{$ahead}', 'confirmed', '2026-11-01 00:00:00')");
        $this->service = new CommonAreaBookingMode($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'booking_mode' => 'approval'];
        self::assertNull(CommonAreaBookingMode::problem($row, 'blocked', 0));
        self::assertNull(CommonAreaBookingMode::problem($row, 'free', 3), 'Liberar não depende das reservas.');
        self::assertNull(CommonAreaBookingMode::problem(['booking_mode' => 'blocked'] + $row, 'approval', 2));
        self::assertStringContainsString('não encontrada', (string)CommonAreaBookingMode::problem(null, 'blocked', 0));
        self::assertStringContainsString('inativa', (string)CommonAreaBookingMode::problem(['status' => 'inactive'] + $row, 'blocked', 0));
        self::assertStringContainsString('já está', (string)CommonAreaBookingMode::problem($row, 'approval', 0));
        self::assertStringContainsString('2 reserva(s)', (string)CommonAreaBookingMode::problem($row, 'blocked', 2));
        self::assertStringContainsString('1 reserva(s)', (string)CommonAreaBookingMode::problem($row, 'none', 1));
        self::assertSame(['approval', 'free', 'blocked', 'none'], array_keys(CommonAreaBookingMode::modes()));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'area_ids' => [2], 'booking_mode' => 'blocked', 'reason' => 'Dedetização do prédio'];
        foreach ([['condominium_id' => 0] + $ok, ['booking_mode' => 'closed'] + $ok, ['booking_mode' => null] + $ok, ['reason' => 'abc'] + $ok,
            ['area_ids' => []] + $ok, ['area_ids' => null] + $ok, ['area_ids' => ['x']] + $ok, ['area_ids' => range(1, 101)] + $ok] as $input) {
            try {
                $this->service->change($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->change($this->user(), ['condominium_id' => 3, 'area_ids' => [5]] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testRefusalsBeforeTheForm(): void
    {
        $ok = ['condominium_id' => 1, 'booking_mode' => 'blocked', 'reason' => 'Dedetização do prédio'];
        foreach ([[1, '2 reserva(s)'], [3, 'já está'], [4, 'inativa'], [5, 'não encontrada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->change($this->user(), ['area_ids' => [$id]] + $ok);
                self::fail("Área {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_area']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame(['approval', 'free'], [$this->mode(1), $this->mode(2)], 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/CommonAreaBookingMode.php');
        foreach (["\$registry->save(\$user, 'common_areas'", "RegistryService::definition('common_areas')", ' FOR UPDATE', "Audit::record('booking_mode'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "status IN ('active', 'confirmed') AND reserved_on >= ?"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['notify', 'NotificationOutbox', 'mail_queue', 'UPDATE erp_reservations', 'UPDATE erp_common_areas', 'resident_name', 'unit_id'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo cadastro, sem aviso e sem dado de morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/CommonAreaBookingMode.php');
        foreach (["Access::can('erp.common_areas.view', \$user)", "Access::can('erp.common_areas.manage', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/common-areas/booking-mode", "CommonAreaBookingMode:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function mode(int $id): string
    {
        return (string)$this->pdo->query("SELECT booking_mode FROM erp_common_areas WHERE id = {$id}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
