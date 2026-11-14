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
use Source\Services\Erp\UserAccessRevocation;

/**
 * ADR 0283: acesso ao condomínio tirado em lote de quem não entra no ERP. Unit com SQLite (Pdo\Sqlite quando existe; PDO
 * no PHP 8.2; o PDO de teste tira o FOR UPDATE) para a regra pura (causa pela regra de ErpAccess::effective, recusas), a
 * validação, o escopo, as recusas (uma por caso, tudo desfeito, sem nome nem e-mail na mensagem), a prévia, os que não
 * podem ser alterados de fora, a saída só da linha deste condomínio pelo ErpAccess::deactivate (papéis, exceções e conta
 * intactos) e o contrato. As causas vêm de UserAccessRevocation::CAUSES. O MariaDB confere com o controle de acesso de
 * verdade (Integration\UserAccessRevocationTest).
 */
final class UserAccessRevocationTest extends TestCase
{
    private PDO $pdo;
    private UserAccessRevocation $service;

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
        foreach (['CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, document TEXT, status TEXT)',
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT, name TEXT, level INTEGER)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER, granted_by INTEGER)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec("INSERT INTO access_roles (id, slug, name, level) VALUES (1, 'gestor', 'Gestor', 50), (2, 'sindico', 'Síndico', 20), (3, 'leitor', 'Leitor', 10),
            (4, 'chefe', 'Chefe', 60)");
        $this->pdo->exec("INSERT INTO access_permissions (id, slug) VALUES (1, 'erp.access'), (2, 'erp.reports.view')");
        $this->pdo->exec('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (1, 1), (2, 1), (3, 2)');
        $user = $this->pdo->prepare("INSERT INTO users (id, first_name, last_name, email, document, status) VALUES (?, 'Fulano', 'de Tal', ?, '123.456.789-00', 'confirmed')");
        foreach ([7, 11, 12, 13, 14, 15, 16, 17, 20] as $id) {
            $user->execute([$id, "fulano{$id}@exemplo.com"]);
        }
        foreach ([[7, 1], [11, 1], [12, 1], [13, 1], [14, 1], [15, 1], [16, 1], [16, 3], [17, 1], [20, 3], [12, 3]] as [$id, $condominium]) {
            $this->pdo->prepare('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (?, ?)')->execute([$id, $condominium]);
        }
        foreach ([[7, 1], [11, 2], [12, 3], [14, 3], [15, 2], [16, 1], [17, 4], [20, 3]] as [$id, $role]) {
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        }
        foreach ([[14, 1, 'allow'], [15, 1, 'deny']] as $row) {
            $this->pdo->prepare('INSERT INTO access_user_overrides (user_id, permission_id, effect) VALUES (?, ?, ?)')->execute($row);
        }
        $this->service = new UserAccessRevocation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        self::assertNull(UserAccessRevocation::cause('gestor', true, null), 'Papel com erp.access entra.');
        self::assertNull(UserAccessRevocation::cause('leitor', false, 'allow'), 'O allow concede.');
        self::assertSame('no_role', UserAccessRevocation::cause(null, false, 'allow'), 'Sem papel nunca entra.');
        self::assertSame('denied', UserAccessRevocation::cause('gestor', true, 'deny'), 'O deny vence.');
        self::assertSame('role_without_erp', UserAccessRevocation::cause('leitor', false, null));
        foreach (['no_role', 'denied', 'role_without_erp'] as $cause) {
            self::assertArrayHasKey($cause, UserAccessRevocation::CAUSES);
        }
        $row = ['id' => 12, 'cause' => 'role_without_erp', 'manageable' => true];
        self::assertNull(UserAccessRevocation::problem($row, false));
        self::assertStringContainsString('não tem acesso', (string)UserAccessRevocation::problem(null, false));
        self::assertStringContainsString('próprio', (string)UserAccessRevocation::problem($row, true));
        self::assertStringContainsString('entra no ERP', (string)UserAccessRevocation::problem(['cause' => null] + $row, false));
        self::assertStringContainsString('não pode ser alterado', (string)UserAccessRevocation::problem(['manageable' => false] + $row, false));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Revisão dos acessos'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('x', 256)] + $ok, ['user_ids' => []] + $ok,
            ['user_ids' => ['x']] + $ok, ['user_ids' => 'abc'] + $ok, ['user_ids' => range(1, UserAccessRevocation::MAX_USERS + 1)] + $ok] as $input) {
            try {
                $this->service->revoke($this->user(), $input);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->revoke($this->user(), ['condominium_id' => 3] + $ok);
    }

    public function testChosenIdsRefusedAllOrNothing(): void
    {
        foreach ([[11, 'entra no ERP'], [14, 'entra no ERP'], [7, 'próprio'], [17, 'não pode ser alterado'], [20, 'não tem acesso'], [99, 'não tem acesso']] as [$id, $message]) {
            try {
                $this->service->revoke($this->user(), ['condominium_id' => 1, 'user_ids' => [12, $id], 'reason' => 'Revisão dos acessos']);
                self::fail("Usuário {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_user'], 'O 12 não entra no ERP e passa; a recusa é a do outro.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Fulano', 'exemplo', '123.456'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(8, $this->accessRows(1), 'Nada saiu.');
        }
    }

    public function testDryRunThenRevokeWithoutIds(): void
    {
        $roles = (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles')->fetchColumn();
        $overrides = (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_overrides')->fetchColumn();
        $preview = $this->service->revoke($this->user(), ['condominium_id' => '1', 'reason' => 'Revisão dos acessos', 'dry_run' => true]);
        self::assertSame([12, 13, 15], array_column($preview['users'], 'id'), 'Os que não entram no ERP; o 17 (papel acima) fica de fora.');
        self::assertSame(['role_without_erp', 'no_role', 'denied'], array_column($preview['users'], 'cause'));
        self::assertSame([3, 0, 1], [$preview['count'], $preview['changed'], $preview['skipped_unmanageable']]);
        self::assertSame(array_keys(UserAccessRevocation::CAUSES), array_column($preview['by_cause'], 'cause'), 'Todas as causas, sempre.');
        self::assertSame($preview['count'], array_sum(array_column($preview['by_cause'], 'users')), 'O total é a soma por causa.');
        self::assertSame(8, $this->accessRows(1), 'A prévia desfaz tudo.');

        $result = $this->service->revoke($this->user(), ['condominium_id' => 1, 'reason' => 'Revisão dos acessos']);
        self::assertSame([3, 3], [$result['count'], $result['changed']]);
        self::assertSame([7, 11, 14, 16, 17], array_map('intval', $this->pdo->query('SELECT user_id FROM user_condominium_access WHERE condominium_id = 1 ORDER BY user_id')
            ->fetchAll(PDO::FETCH_COLUMN)));
        self::assertSame(3, $this->accessRows(3), 'O acesso a outro condomínio fica (inclusive o do 12).');
        self::assertSame($roles, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles')->fetchColumn(), 'Papéis intactos.');
        self::assertSame($overrides, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_overrides')->fetchColumn(), 'Exceções intactas.');
        self::assertSame(9, (int)$this->pdo->query("SELECT COUNT(*) FROM users WHERE status = 'confirmed'")->fetchColumn(), 'Contas intactas.');
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'de Tal', 'exemplo', '123.456'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/UserAccessRevocation.php');
        foreach (['$this->access->deactivate($user, ', 'ErpAccess::effective(', 'ErpAccess::canManage(', 'UserAccessReport::topRole(', ' FOR UPDATE',
            "Audit::record('revoke_no_erp'", '$this->pdo->rollBack()', '$this->scope->assertAccess('] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'first_name', 'email', 'document', 'password', 'two_factor', 'status ='] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo desativar da tela, sem mexer em conta, senha ou 2FA e sem dado pessoal: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/UserAccessRevocation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.users.view', \$user)", "Access::can('erp.users.manage', \$user)",
            'catch (CondominiumAccessDenied | RegistryNotFound)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/users/revoke-condominium-access", "UserAccessRevocation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/user-access"'), strpos($index, $route), 'Logo depois dos acessos dos usuários.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/user-access:\n"), strpos($openapi, "  /users/revoke-condominium-access:\n"));
    }

    private function accessRows(int $condominium): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM user_condominium_access WHERE condominium_id = {$condominium}")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
