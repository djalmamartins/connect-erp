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
use Source\Services\Erp\VisitorAuthorizationExpiry;

/**
 * ADR 0235: bloqueio em lote das autorizações de visitantes vencidas e ainda liberadas. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura, a validação, o escopo, as recusas antes do
 * formulário e o contrato de reuso; a regravação pelo cadastro, com auditoria, é conferida no MariaDB
 * (Integration\VisitorAuthorizationExpiryTest).
 */
final class VisitorAuthorizationExpiryTest extends TestCase
{
    private PDO $pdo;
    private VisitorAuthorizationExpiry $service;

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
            'CREATE TABLE erp_visitors (id INTEGER PRIMARY KEY, condominium_id INTEGER, unit_id INTEGER, unit_label TEXT, name TEXT, document TEXT, phone TEXT,
                permanent_access INTEGER, valid_until TEXT, notes TEXT, status TEXT, deleted_at TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $this->pdo->exec("INSERT INTO erp_visitors (id, condominium_id, unit_label, name, permanent_access, valid_until, status, deleted_at) VALUES
            (1, 1, 'Apto 101', 'Fulano Diarista', 0, '2026-10-05', 'active', NULL), (2, 1, 'Apto 101', 'Fulano', 0, '2026-10-05', 'inactive', NULL),
            (3, 1, 'Apto 102', 'Beltrano', 1, NULL, 'active', NULL), (4, 1, 'Apto 102', 'Beltrano', 0, NULL, 'active', NULL),
            (5, 1, 'Apto 103', 'Ciclano', 0, '2026-11-20', 'active', NULL), (6, 3, 'Apto 9', 'Outro', 0, '2026-10-05', 'active', NULL),
            (7, 1, 'Apto 101', 'Excluído', 0, '2026-10-05', 'active', '2026-10-06 00:00:00')");
        $this->service = new VisitorAuthorizationExpiry($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testProblemPureRule(): void
    {
        $row = ['status' => 'active', 'permanent_access' => 0, 'valid_until' => '2026-10-31'];
        self::assertNull(VisitorAuthorizationExpiry::problem($row, '2026-11-01'), 'Venceu no dia anterior ao corte.');
        self::assertNull(VisitorAuthorizationExpiry::problem(['valid_until' => '2026-10-31 00:00:00'] + $row, '2026-11-01'));
        self::assertStringContainsString('não encontrado', (string)VisitorAuthorizationExpiry::problem(null, '2026-11-01'));
        self::assertStringContainsString('já está bloqueado', (string)VisitorAuthorizationExpiry::problem(['status' => 'inactive'] + $row, '2026-11-01'));
        self::assertStringContainsString('Acesso permanente', (string)VisitorAuthorizationExpiry::problem(['permanent_access' => 1] + $row, '2026-11-01'));
        self::assertStringContainsString('sem data', (string)VisitorAuthorizationExpiry::problem(['valid_until' => null] + $row, '2026-11-01'));
        self::assertStringContainsString('ainda não venceu', (string)VisitorAuthorizationExpiry::problem(['valid_until' => '2026-11-01'] + $row, '2026-11-01'));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Autorização vencida', 'before' => '2026-11-01'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => ''] + $ok, ['before' => '01/11/2026'] + $ok,
            ['before' => date('Y-m-d', strtotime('+2 days'))] + $ok, ['visitor_ids' => []] + $ok, ['visitor_ids' => ['x']] + $ok,
            ['visitor_ids' => range(1, 501)] + $ok] as $input) {
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
        foreach ([[2, 'já está bloqueado'], [3, 'Acesso permanente'], [4, 'sem data'], [5, 'ainda não venceu'], [6, 'não encontrado'], [7, 'não encontrado'],
            [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->expire($this->user(), ['condominium_id' => 1, 'visitor_ids' => [1, $id], 'reason' => 'Autorização vencida', 'before' => '2026-11-01']);
                self::fail("Visitante {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_visitor']);
                self::assertStringContainsString($message, $exception->errors['_form']);
                self::assertStringNotContainsString('Fulano', $exception->errors['_form']);
            }
            self::assertFalse($this->pdo->inTransaction());
        }
        self::assertSame('active', $this->pdo->query('SELECT status FROM erp_visitors WHERE id = 1')->fetchColumn(), 'Nada gravado.');
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/VisitorAuthorizationExpiry.php');
        foreach (["\$registry->save(\$user, 'visitors', array_merge(\$data, ['status' => 'inactive'])", ' FOR UPDATE', "Audit::record('expire'",
            '$this->pdo->rollBack()', '$this->scope->assertAccess(', 'permanent_access = 0 AND valid_until IS NOT NULL AND valid_until < ?'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE erp_', 'INSERT ', 'NotificationOutbox', 'mail_queue', "'name' =>", "['name']", "['document']", "['phone']", "'unit_id' =>"] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo formulário, sem aviso e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/VisitorAuthorizationExpiry.php');
        foreach (["Access::can('erp.visitors.view', \$user)", "Access::can('erp.visitors.manage', \$user)", 'catch (CondominiumAccessDenied)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        self::assertStringContainsString('$route->post("/visitors/expire", "VisitorAuthorizationExpiry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);',
            (string)file_get_contents($root . '/index.php'));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
