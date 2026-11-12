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
use Source\Services\Erp\VisitForecastExpiry;

/**
 * ADR 0231: baixa em lote das visitas previstas vencidas em aberto. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP
 * 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do formulário e o
 * contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB (Integration\VisitForecastExpiryTest).
 */
final class VisitForecastExpiryTest extends TestCase
{
    private PDO $pdo;
    private VisitForecastExpiry $service;

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
            'CREATE TABLE erp_visit_forecasts (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, visitor_name TEXT, visitor_document TEXT, expected_on TEXT,
                expected_until TEXT, authorized_by TEXT, notes TEXT, status TEXT, deleted_at TEXT)',
            'CREATE TABLE erp_access_logs (id INTEGER PRIMARY KEY, condominium_id INTEGER, visit_forecast_id INTEGER, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_visit_forecasts (id, condominium_id, unit_id, visitor_name, expected_on, expected_until, status) VALUES
            (1, 1, 11, 'Fulano', '2026-10-05', NULL, 'active'), (2, 1, 11, 'Fulano', '2026-10-06', NULL, 'done'),
            (3, 1, 12, 'Beltrano', '2026-10-20', '2026-11-20', 'active'), (4, 3, 21, 'Outro', '2026-10-05', NULL, 'active'),
            (5, 1, 12, 'Beltrano', '2026-10-07', NULL, 'active'), (6, 1, 12, 'Beltrano', '2026-10-08', NULL, 'active')");
        $this->pdo->exec("INSERT INTO erp_access_logs (condominium_id, visit_forecast_id, status, deleted_at) VALUES
            (1, 5, 'active', NULL), (1, 6, 'inactive', NULL), (1, 6, 'active', '2026-10-09 00:00:00')");   // 6: acesso cancelado ou excluído não conta
        $this->service = new VisitForecastExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'expected_on' => '2026-10-05', 'expected_until' => null];
        self::assertNull(VisitForecastExpiry::problem($row, '2026-11-01'));
        self::assertNull(VisitForecastExpiry::problem(['expected_until' => '2026-10-31'] + $row, '2026-11-01'));
        self::assertSame('2026-11-20', VisitForecastExpiry::lastDay(['expected_until' => '2026-11-20 00:00:00'] + $row));
        self::assertSame('2026-10-05', VisitForecastExpiry::lastDay(['expected_until' => ''] + $row));
        self::assertStringContainsString('não encontrada', (string)VisitForecastExpiry::problem(null, '2026-11-01'));
        self::assertStringContainsString('não está em aberto', (string)VisitForecastExpiry::problem(['status' => 'done'] + $row, '2026-11-01'));
        self::assertStringContainsString('não está em aberto', (string)VisitForecastExpiry::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não passou', (string)VisitForecastExpiry::problem(['expected_until' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não passou', (string)VisitForecastExpiry::problem(['expected_on' => '2026-11-01'] + $row, '2026-11-01'));
        self::assertStringContainsString('acesso registrado', (string)VisitForecastExpiry::problem($row, '2026-11-01', true));
        self::assertStringContainsString('não está em aberto', (string)VisitForecastExpiry::problem(['status' => 'done'] + $row, '2026-11-01', true));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Visita não aconteceu', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['forecast_ids' => []] + $ok, ['forecast_ids' => ['x']] + $ok,
            ['forecast_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[2, 'não está em aberto'], [3, 'ainda não passou'], [4, 'não encontrada'], [99, 'não encontrada'], [5, 'acesso registrado']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'forecast_ids' => [6, 1, $id], 'reason' => 'Visita não aconteceu', 'before' => '2026-11-01']);
                self::fail("Visita {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_forecast']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_visit_forecasts WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VisitForecastExpiry.php');
        foreach (["\$registry->save(\$user, 'visit_forecasts', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', "status = 'active'\n                    AND COALESCE(expected_until, expected_on) < ?"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', 'mail_queue', "'visitor_name' =>", "['visitor_name']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem visitante: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VisitForecastExpiry.php');
        foreach (["Access::can('erp.visit_forecasts.view', \$user)", "Access::can('erp.visit_forecasts.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/visit-forecasts/expire", "VisitForecastExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
