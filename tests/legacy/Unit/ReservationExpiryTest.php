<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ReservationExpiry;

/**
 * ADR 0221: baixa das reservas que passaram sem aprovação. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o
 * PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, a recusa antes do formulário e o contrato de
 * reuso; a regravação pelo formulário é conferida no MariaDB (Integration\ReservationExpiryTest).
 */
final class ReservationExpiryTest extends TestCase
{
    private PDO $pdo;
    private ReservationExpiry $service;

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
            'CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT,
                reserved_on TEXT, starts_time TEXT, ends_time TEXT, fee_cents INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_reservations (id, condominium_id, common_area_id, unit_id, resident_name, reserved_on, status) VALUES
            (1, 1, 5, 11, 'Fulano', '2026-10-05', 'active'), (2, 1, 5, 12, 'Beltrano', '2026-10-06', 'confirmed'),
            (3, 1, 5, 11, 'Fulano', '2026-11-20', 'active'), (4, 3, 6, 21, 'Outro', '2026-10-05', 'active')");
        $this->service = new ReservationExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'reserved_on' => '2026-10-05'];
        self::assertNull(ReservationExpiry::problem($row, '2026-11-11'));
        self::assertNull(ReservationExpiry::problem(['reserved_on' => '2026-11-10 00:00:00'] + $row, '2026-11-11'));
        self::assertStringContainsString('não encontrada', (string)ReservationExpiry::problem(null, '2026-11-11'));
        self::assertStringContainsString('aguardando aprovação', (string)ReservationExpiry::problem(['status' => 'confirmed'] + $row, '2026-11-11'));
        self::assertStringContainsString('aguardando aprovação', (string)ReservationExpiry::problem(['status' => 'inactive'] + $row, '2026-11-11'));
        self::assertStringContainsString('ainda não passou', (string)ReservationExpiry::problem(['reserved_on' => '2026-11-11'] + $row, '2026-11-11'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Data passou sem aprovação', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['reservation_ids' => []] + $ok, ['reservation_ids' => ['x']] + $ok,
            ['reservation_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->expire($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->expire($this->user(), ['condominium_id' => 3] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        foreach ([[2, 'aguardando aprovação'], [3, 'ainda não passou'], [4, 'não encontrada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'reservation_ids' => [1, $id], 'reason' => 'Data passou sem aprovação', 'before' => '2026-11-11']);
                self::fail("Reserva {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_reservation']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_reservations WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ReservationExpiry.php');
        foreach (["\$registry->save(\$user, 'reservations', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', "'resident_name' =>", "['resident_name']"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ReservationExpiry.php');
        foreach (["Access::can('erp.reservations.view', \$user)", "Access::can('erp.reservations.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/reservations/expire-pending", "ReservationExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
