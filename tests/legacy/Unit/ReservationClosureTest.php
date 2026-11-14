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
use Source\Services\Erp\ReservationClosure;

/**
 * ADR 0263: cancelamento em lote das reservas de uma área interditada. Unit com SQLite (Pdo\Sqlite quando existe; PDO no
 * PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação (período, área, motivo), o escopo, as recusas
 * antes do formulário, as contagens das que ficam de fora, a lista de espera a rever e o contrato de reuso; a regravação
 * pelo cadastro, com auditoria, é conferida no MariaDB (Integration\ReservationClosureTest).
 */
final class ReservationClosureTest extends TestCase
{
    private PDO $pdo;
    private ReservationClosure $service;
    /** @var array{0:string, 1:string} */
    private array $period;

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
            "CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, booking_mode TEXT DEFAULT 'approval', status TEXT, deleted_at TEXT)",
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_extra_charges (id INTEGER PRIMARY KEY, reservation_id INTEGER, kind TEXT, status TEXT)',
            'CREATE TABLE erp_waitlist (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, desired_on TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_reservations (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT, reserved_on TEXT,
                starts_time TEXT, ends_time TEXT, fee_cents INTEGER, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $d = static fn (int $days): string => date('Y-m-d', strtotime("+{$days} days"));
        $this->period = [$d(5), $d(30)];
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name, status, deleted_at) VALUES (20, 1, 'Piscina', 'active', NULL), (21, 1, 'Salão', 'active', NULL),
            (22, 1, 'Quadra', 'inactive', NULL), (23, 3, 'De fora', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name, booking_mode, status, deleted_at) VALUES (24, 1, 'Churrasqueira', 'blocked', 'active', NULL)");
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, status, deleted_at) VALUES (40, 1, 'active', NULL), (41, 1, 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_reservations (id, condominium_id, common_area_id, unit_id, resident_name, reserved_on, fee_cents, status, deleted_at) VALUES
            (1, 1, 20, 40, 'Fulano de Tal', '{$d(10)}', 15000, 'active', NULL), (2, 1, 20, 40, 'Fulano', '{$d(20)}', NULL, 'confirmed', NULL),
            (3, 1, 20, 40, 'Cancelada', '{$d(10)}', NULL, 'inactive', NULL), (4, 1, 21, 40, 'Outra área', '{$d(10)}', NULL, 'active', NULL),
            (5, 1, 20, 40, 'Fora do período', '{$d(100)}', NULL, 'active', NULL), (6, 1, 20, 40, 'Cobrada', '{$d(20)}', 8000, 'confirmed', NULL),
            (7, 1, 20, 41, 'Unidade inativa', '{$d(10)}', NULL, 'active', NULL), (8, 3, 23, 50, 'De fora', '{$d(10)}', NULL, 'active', NULL),
            (9, 1, 20, 40, 'Excluída', '{$d(10)}', NULL, 'active', '2026-01-01 00:00:00')");
        $this->pdo->exec("INSERT INTO erp_extra_charges (id, reservation_id, kind, status) VALUES (800, 6, 'extra', 'active'), (801, 2, 'extra', 'cancelled')");
        $this->pdo->exec("INSERT INTO erp_waitlist (condominium_id, common_area_id, desired_on, status, deleted_at) VALUES (1, 20, '{$d(12)}', 'active', NULL),
            (1, 20, '{$d(12)}', 'served', NULL), (1, 21, '{$d(12)}', 'active', NULL), (1, 20, '{$d(200)}', 'active', NULL)");
        $this->service = new ReservationClosure($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['common_area_id' => 20, 'status' => 'active', 'reserved_on' => '2026-12-10'];
        self::assertNull(ReservationClosure::problem($row, 20, '2026-12-01', '2026-12-31'));
        self::assertNull(ReservationClosure::problem(['status' => 'confirmed', 'reserved_on' => '2026-12-31'] + $row, 20, '2026-12-01', '2026-12-31'), 'Confirmada e o último dia entram.');
        self::assertStringContainsString('não encontrada', (string)ReservationClosure::problem(null, 20, '2026-12-01', '2026-12-31'));
        self::assertStringContainsString('não encontrada', (string)ReservationClosure::problem($row, 21, '2026-12-01', '2026-12-31'));
        self::assertStringContainsString('já está cancelada', (string)ReservationClosure::problem(['status' => 'inactive'] + $row, 20, '2026-12-01', '2026-12-31'));
        self::assertStringContainsString('fora do período', (string)ReservationClosure::problem($row, 20, '2026-12-11', '2026-12-31'));
        self::assertStringContainsString('já foi cobrada', (string)ReservationClosure::problem($row, 20, '2026-12-01', '2026-12-31', true));
        self::assertStringContainsString('unidade', (string)ReservationClosure::problem($row, 20, '2026-12-01', '2026-12-31', false, false));
    }

    public function testValidationAndScope(): void
    {
        [$from, $to] = $this->period;
        $ok = ['condominium_id' => 1, 'common_area_id' => 20, 'from' => $from, 'to' => $to, 'reason' => 'Reforma da piscina'];
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        foreach ([['condominium_id' => 0] + $ok, ['common_area_id' => 'x'] + $ok, ['from' => '2026-02-30'] + $ok, ['to' => null] + $ok, ['from' => $yesterday] + $ok,
            ['to' => $yesterday, 'from' => $from] + $ok, ['to' => date('Y-m-d', strtotime('+400 days'))] + $ok, ['reason' => 'abc'] + $ok,
            ['common_area_id' => 22] + $ok, ['common_area_id' => 24] + $ok, ['common_area_id' => 23] + $ok, ['common_area_id' => 99] + $ok, ['reservation_ids' => []] + $ok,
            ['reservation_ids' => ['x']] + $ok, ['reservation_ids' => range(1, 501)] + $ok] as $input) {
            try {
                $this->service->cancel($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        try {
            $this->service->cancel($this->user(), ['condominium_id' => 3, 'common_area_id' => 23] + $ok);
            self::fail('Fora do escopo passou.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    public function testChosenIdsRefusedBeforeTheForm(): void
    {
        [$from, $to] = $this->period;
        foreach ([[3, 'já está cancelada'], [4, 'não encontrada'], [5, 'fora do período'], [6, 'já foi cobrada'], [7, 'unidade'], [8, 'não encontrada'],
            [9, 'não encontrada'], [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'common_area_id' => 20, 'from' => $from, 'to' => $to, 'reservation_ids' => [1, $id],
                    'reason' => 'Reforma da piscina']);
                self::fail("Reserva {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_reservation']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_reservations WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testWithoutIdsTheOthersAreCountedAndLeftOut(): void
    {
        [$from, $to] = $this->period;
        $this->pdo->exec("UPDATE erp_reservations SET deleted_at = '2026-01-01 00:00:00' WHERE id IN (1, 2)");
        $result = $this->service->cancel($this->user(), ['condominium_id' => '1', 'common_area_id' => '20', 'from' => $from, 'to' => $to, 'reason' => 'Reforma da piscina',
            'dry_run' => true]);
        self::assertSame([1, 1, 1, 0, 0, 0], [$result['skipped_billed'], $result['skipped_inactive_unit'], $result['waitlist_to_review'], $result['count'], $result['confirmed'],
            $result['changed']]);
        self::assertFalse($this->pdo->inTransaction());
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/ReservationClosure.php');
        foreach (["\$registry->save(\$user, 'reservations', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('closure_cancel'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "RegistryService::definition('reservations')", "kind = 'extra' AND status = 'active'"]
            as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'DELETE ', 'NotificationOutbox', 'mail_queue', "'resident_name' =>", "['resident_name']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem morador nem unidade na resposta: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/ReservationClosure.php');
        foreach (["Access::can('erp.reservations.view', \$user)", "Access::can('erp.reservations.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/reservations/cancel-for-closure", "ReservationClosure:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
