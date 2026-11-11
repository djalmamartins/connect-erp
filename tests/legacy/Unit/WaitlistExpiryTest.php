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
use Source\Services\Erp\WaitlistExpiry;

/**
 * ADR 0237: baixa em lote das inscrições da lista de espera cuja data passou. Unit com SQLite (Pdo\Sqlite quando existe;
 * PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\WaitlistExpiryTest).
 */
final class WaitlistExpiryTest extends TestCase
{
    private PDO $pdo;
    private WaitlistExpiry $service;

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
            'CREATE TABLE erp_common_areas (id INTEGER PRIMARY KEY, condominium_id INTEGER, name TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, label TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_waitlist (id INTEGER PRIMARY KEY, condominium_id INTEGER, common_area_id INTEGER, unit_id INTEGER, resident_name TEXT, desired_on TEXT,
                notes TEXT, called_at TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_common_areas (id, condominium_id, name, status, deleted_at) VALUES (1, 1, 'Salão', 'active', NULL), (2, 1, 'Quadra', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_units (id, condominium_id, label, status, deleted_at) VALUES (11, 1, 'Apto 101', 'active', NULL), (12, 1, 'Apto 102', 'inactive', NULL)");
        $this->pdo->exec("INSERT INTO erp_waitlist (id, condominium_id, common_area_id, unit_id, resident_name, desired_on, status, deleted_at) VALUES
            (1, 1, 1, 11, 'Fulano', '2026-10-05', 'active', NULL), (2, 1, 1, 11, 'Fulano', '2026-10-06', 'served', NULL),
            (3, 1, 1, 11, 'Fulano', '2026-11-20', 'active', NULL), (4, 3, 9, 99, 'Outro', '2026-10-05', 'active', NULL),
            (5, 1, 2, 11, 'Fulano', '2026-10-07', 'active', NULL), (6, 1, 1, 12, 'Beltrano', '2026-10-08', 'active', NULL),
            (7, 1, 1, 11, 'Excluída', '2026-10-05', 'active', '2026-10-06 00:00:00')");
        $this->service = new WaitlistExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'desired_on' => '2026-10-31'];
        self::assertNull(WaitlistExpiry::problem($row, '2026-11-01'), 'A data foi o dia anterior ao corte.');
        self::assertNull(WaitlistExpiry::problem(['desired_on' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrada', (string)WaitlistExpiry::problem(null, '2026-11-01'));
        self::assertStringContainsString('não está aguardando', (string)WaitlistExpiry::problem(['status' => 'served'] + $row, '2026-11-01'));
        self::assertStringContainsString('não está aguardando', (string)WaitlistExpiry::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não passou', (string)WaitlistExpiry::problem(['desired_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('inativa', (string)WaitlistExpiry::problem($row, '2026-11-01', false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Data passou sem vaga', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['entry_ids' => []] + $ok, ['entry_ids' => ['x']] + $ok,
            ['entry_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[2, 'não está aguardando'], [3, 'ainda não passou'], [4, 'não encontrada'], [5, 'inativa'], [6, 'inativa'], [7, 'não encontrada'],
            [99, 'não encontrada']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'entry_ids' => [1, $id], 'reason' => 'Data passou sem vaga', 'before' => '2026-11-01']);
                self::fail("Inscrição {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_entry']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_waitlist WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/WaitlistExpiry.php');
        foreach (["\$registry->save(\$user, 'waitlist', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "status = 'active'\n                    AND desired_on < ?"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', 'mail_queue', "'resident_name' =>", "['resident_name']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem morador: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/WaitlistExpiry.php');
        foreach (["Access::can('erp.reservations.view', \$user)", "Access::can('erp.reservations.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/waitlist/expire", "WaitlistExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
