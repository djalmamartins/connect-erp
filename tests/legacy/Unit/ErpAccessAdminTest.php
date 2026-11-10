<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DomainException;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AdminReports;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\Registry\RegistryForbidden;

/** ADR 0168: Administração (AP-091), Relatórios Enviados (AP-100), Usuários (AP-149) e Permissões (AP-150). */
final class ErpAccessAdminTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach ([
            'CREATE TABLE access_roles (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, level INTEGER)',
            'CREATE TABLE access_user_roles (user_id INTEGER PRIMARY KEY, role_id INTEGER, assigned_by INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, name TEXT, slug TEXT, group_name TEXT, description TEXT)',
            'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER, PRIMARY KEY (role_id, permission_id))',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT, assigned_by INTEGER, PRIMARY KEY (user_id, permission_id))',
            "CREATE TABLE users (id INTEGER PRIMARY KEY, first_name TEXT, last_name TEXT, email TEXT, status TEXT DEFAULT 'confirmed', level INTEGER DEFAULT 1)",
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER, granted_by INTEGER, PRIMARY KEY (user_id, condominium_id))',
            'CREATE TABLE erp_settings (id INTEGER PRIMARY KEY, condominium_id INTEGER, setting_key TEXT, value_json TEXT)',
            "CREATE TABLE erp_units (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_residents (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            "CREATE TABLE erp_bank_accounts (id INTEGER PRIMARY KEY, condominium_id INTEGER, status TEXT DEFAULT 'active', deleted_at TEXT)",
            'CREATE TABLE erp_month_closings (id INTEGER PRIMARY KEY, condominium_id INTEGER, competence TEXT, status TEXT)',
            'CREATE TABLE system_audit_logs (id INTEGER PRIMARY KEY, users_id INTEGER, action TEXT, entity TEXT, entity_id INTEGER, context_json TEXT, ip TEXT, created_at TEXT)',
        ] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO access_roles (id, name, slug, level) VALUES (1, 'Desenvolvedor', 'developer', 100), (2, 'Super', 'super_admin', 90),
            (3, 'Administrador do cliente', 'client_admin', 70), (4, 'Gerente', 'manager', 50), (5, 'Operador', 'operator', 30), (6, 'Usuário', 'user', 10)");
        $this->pdo->exec("INSERT INTO access_permissions (id, name, slug, group_name) VALUES (1, 'ERP', 'erp.access', 'ERP'), (2, 'Todos', 'erp.condominiums.all', 'ERP'),
            (3, 'Ver usuários', 'erp.users.view', 'ERP Usuários'), (4, 'Gerir usuários', 'erp.users.manage', 'ERP Usuários'), (5, 'Ver lançamentos', 'erp.entries.view', 'ERP'),
            (6, 'Gerir permissões', 'erp.permissions.manage', 'ERP Usuários'), (7, 'Studio', 'studio.access', 'Studio'), (8, 'Auditoria', 'erp.audit.view', 'ERP')");
        $this->pdo->exec('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (2,1),(2,2),(2,3),(2,4),(2,5),(2,6),(2,8),(3,1),(3,3),(3,4),(3,5),(3,6),(4,1),(4,5),(5,1)');
        $this->pdo->exec("INSERT INTO users (id, first_name, last_name, email) VALUES (1, 'Dev', 'X', 'dev@x.test'), (2, 'Super', 'X', 'super@x.test'),
            (7, 'Ana', 'Admin', 'ana@x.test'), (8, 'Bruno', 'Gerente', 'bruno@x.test'), (9, 'Caio', 'Outro', 'caio@x.test'), (10, 'Duda', 'Operadora', 'Ops@X.test'),
            (11, 'Edu', 'Usuario', 'user@x.test'), (12, 'Fabi', 'Par', 'fabi@x.test')");
        $this->pdo->exec('INSERT INTO access_user_roles (user_id, role_id) VALUES (1,1),(2,2),(7,3),(8,4),(9,5),(10,5),(11,6),(12,3)');
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (2, 'Bosque'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7,1),(7,2),(8,1),(9,3),(12,1)');
    }

    public function testPureRules(): void
    {
        self::assertTrue(ErpAccess::canManage(['slug' => 'client_admin', 'level' => 70], ['slug' => 'manager', 'level' => 50], false));
        self::assertFalse(ErpAccess::canManage(['slug' => 'client_admin', 'level' => 70], ['slug' => 'client_admin', 'level' => 70], false), 'Mesmo nível não.');
        self::assertFalse(ErpAccess::canManage(['slug' => 'client_admin', 'level' => 70], ['slug' => 'manager', 'level' => 50], true), 'Nunca em si mesmo.');
        self::assertFalse(ErpAccess::canManage(['slug' => null, 'level' => 0], ['slug' => null, 'level' => 0], false), 'Sem papel, nega.');
        self::assertFalse(ErpAccess::canManage(['slug' => 'super_admin', 'level' => 90], ['slug' => 'super_admin', 'level' => 10], false), 'Papel protegido.');
        self::assertTrue(ErpAccess::canManage(['slug' => 'developer', 'level' => 100], ['slug' => 'super_admin', 'level' => 90], false));
        self::assertSame([false, true, false, true, false], [ErpAccess::effective(false, true, 'allow'), ErpAccess::effective(true, false, 'allow'),
            ErpAccess::effective(true, true, 'deny'), ErpAccess::effective(true, true, null), ErpAccess::effective(true, false, null)]);
    }

    public function testUsersListInviteAndDeactivate(): void
    {
        $service = $this->access();
        $list = $service->users($this->user(7), null);
        self::assertSame([7, 8, 12], array_column($list['rows'], 'id'), 'Só quem tem acesso a condomínios do escopo; o 9 (condomínio 3) não aparece.');
        $byId = array_column($list['rows'], null, 'id');
        self::assertSame([false, true, false], [$byId[7]['manageable'], $byId[8]['manageable'], $byId[12]['manageable']]);
        self::assertSame('b****@x.test', $byId[8]['email'], 'E-mail mascarado.');
        self::assertSame(['Aurora', 'Bosque'], array_column($byId[7]['condominiums'], 'name'));
        $this->expectScope(fn () => $service->users($this->user(7), 3));

        $invited = $service->invite($this->user(7), ' ops@x.TEST ', [1, '2', 2]);
        self::assertSame([10, [1, 2], []], [$invited['user_id'], $invited['granted'], $invited['already']], 'Conta que já existe, com papel de ERP.');
        self::assertSame([[], [1, 2]], [($again = $service->invite($this->user(7), 'ops@x.test', [1, 2]))['granted'], $again['already']]);
        foreach ([['nao-email', [1]], ['ninguem@x.test', [1]], ['user@x.test', [1]], ['ops@x.test', []]] as [$email, $condos]) {
            try {
                $service->invite($this->user(7), $email, $condos);
                self::fail("Convidou {$email}");
            } catch (InvalidArgumentException) {   // 422: e-mail inválido, sem conta (é criada no Studio), papel sem erp.access, sem condomínio
                self::addToAssertionCount(1);
            }
        }
        $this->expectScope(fn () => $service->invite($this->user(7), 'ops@x.test', [3]));
        $this->expectScope(fn () => $service->invite($this->user(7), 'caio@x.test', [1]), 'Conta de outro escopo: 404, sem tomar o usuário.');
        $this->expectForbidden(fn () => $service->invite($this->user(7), 'fabi@x.test', [2]), 'Mesmo nível.');
        $this->expectForbidden(fn () => $service->invite($this->user(7), 'ana@x.test', [2]), 'Si mesmo.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_roles WHERE user_id = 10 AND role_id <> 5')->fetchColumn(), 'O papel não muda.');

        self::assertSame([1], $service->deactivate($this->user(7), 8)['revoked']);
        try {
            $service->deactivate($this->user(7), 10, 1);
            $service->deactivate($this->user(7), 10, 1);
            self::fail('Desativou de novo.');
        } catch (DomainException) {   // 409
            self::addToAssertionCount(1);
        }
        self::assertSame([2], array_map('intval', $this->pdo->query('SELECT condominium_id FROM user_condominium_access WHERE user_id = 10')->fetchAll(PDO::FETCH_COLUMN)));
        $this->expectForbidden(fn () => $service->deactivate($this->user(7), 12));
        $this->expectForbidden(fn () => $service->deactivate($this->user(7), 7));
        $this->expectScope(fn () => $service->deactivate($this->user(7), 9));
        $this->expectScope(fn () => $service->deactivate($this->user(7), 999));
    }

    public function testRolePermissionsFailClosed(): void
    {
        $service = $this->access();
        $matrix = $service->matrix($this->user(7));
        self::assertFalse($matrix['editable_roles'], 'Quem não acessa todos os condomínios não muda papel.');
        self::assertSame(['client_admin', 'manager', 'operator', 'user'], array_column($matrix['roles'], 'slug'), 'Sem developer nem papel acima.');
        self::assertNotContains('studio.access', array_column($matrix['permissions'], 'slug'), 'Só permissões erp.*.');
        $this->expectForbidden(fn () => $service->setRolePermission($this->user(7), 'manager', 'erp.users.view', true));

        $super = $this->user(2);
        self::assertSame(true, $service->setRolePermission($super, 'manager', 'erp.users.view', true)['changed']);
        self::assertSame(false, $service->setRolePermission($super, 'manager', 'erp.users.view', true)['changed']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM access_role_permissions WHERE role_id = 4 AND permission_id = 3')->fetchColumn());
        self::assertSame(true, $service->setRolePermission($super, 'manager', 'erp.users.view', false)['changed']);
        $this->expectForbidden(fn () => $service->setRolePermission($super, 'super_admin', 'erp.users.view', true), 'Papel protegido.');
        $this->expectForbidden(fn () => $service->setRolePermission($super, 'manager', 'erp.condominiums.all', true), 'Escopo de todos: só o desenvolvedor.');
        foreach ([['manager', 'studio.access'], ['manager', 'erp.nao.existe'], ['inexistente', 'erp.users.view'], ['manager', null]] as [$role, $permission]) {
            try {
                $service->setRolePermission($super, $role, $permission, true);
                self::fail('Aceitou ' . json_encode([$role, $permission]));
            } catch (InvalidArgumentException) {   // 422
                self::addToAssertionCount(1);
            }
        }
        self::assertTrue($service->setRolePermission($this->user(1), 'manager', 'erp.condominiums.all', true)['changed'], 'O desenvolvedor pode.');
        $denied = new ErpAccess($this->pdo, new CondominiumScope($this->pdo), static fn (): bool => false);
        $this->expectForbidden(fn () => $denied->setRolePermission($super, 'operator', 'erp.entries.view', true), 'Não concede o que não tem.');
        self::assertFalse($denied->setRolePermission($super, 'operator', 'erp.entries.view', false)['changed'], 'Tirar não exige ter.');
    }

    public function testUserOverrides(): void
    {
        $service = $this->access();
        $admin = $this->user(7);
        $before = array_column($service->userPermissions($admin, 8)['rows'], null, 'slug');
        self::assertSame([true, null, true], [$before['erp.entries.view']['role'], $before['erp.entries.view']['override'], $before['erp.entries.view']['effective']]);
        self::assertFalse($before['erp.users.view']['effective']);
        $service->setUserPermission($admin, 8, 'erp.entries.view', 'deny');
        $service->setUserPermission($admin, 8, 'erp.users.view', 'allow');
        $after = array_column($service->userPermissions($admin, 8)['rows'], null, 'slug');
        self::assertSame([false, true], [$after['erp.entries.view']['effective'], $after['erp.users.view']['effective']]);
        self::assertTrue($service->setUserPermission($admin, 8, 'erp.entries.view', 'inherit')['changed']);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM access_user_overrides WHERE user_id = 8')->fetchColumn());
        $this->expectForbidden(fn () => $service->setUserPermission($admin, 8, 'erp.audit.view', 'allow'), 'O administrador não tem erp.audit.view.');
        $this->expectForbidden(fn () => $service->setUserPermission($admin, 8, 'erp.condominiums.all', 'allow'));
        $this->expectForbidden(fn () => $service->setUserPermission($admin, 8, 'erp.condominiums.all', 'deny'), 'Nem negar: só o desenvolvedor mexe no escopo.');
        $this->expectForbidden(fn () => $service->setUserPermission($admin, 12, 'erp.entries.view', 'deny'));
        $this->expectScope(fn () => $service->setUserPermission($admin, 9, 'erp.entries.view', 'deny'));
        $this->expectScope(fn () => $service->setUserPermission($admin, 11, 'erp.entries.view', 'deny'), 'Sem condomínio no escopo: 404.');
        $this->expectScope(fn () => $service->userPermissions($admin, 9));
        try {
            $service->setUserPermission($admin, 8, 'erp.entries.view', 'maybe');
            self::fail('Aceitou efeito inválido.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $none = array_column($service->userPermissions($this->user(2), 11)['rows'], 'effective');
        self::assertSame([false], array_values(array_unique($none)), 'Papel sem permissão erp: tudo negado.');
    }

    public function testAdministrationOverviewAndSentReports(): void
    {
        $this->pdo->exec("INSERT INTO erp_settings (condominium_id, setting_key, value_json) VALUES (NULL, 'admin.support_email', '\"atendimento@adm.test\"'),
            (1, 'admin.support_phone', '\"999\"')");
        $this->pdo->exec("INSERT INTO erp_units (condominium_id) VALUES (1), (1), (2), (3)");
        $this->pdo->exec("INSERT INTO erp_units (condominium_id, deleted_at) VALUES (1, '2026-01-01')");
        $this->pdo->exec("INSERT INTO erp_residents (condominium_id) VALUES (1), (3)");
        $this->pdo->exec("INSERT INTO erp_bank_accounts (condominium_id) VALUES (2)");
        $this->pdo->exec("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (1, '2026-10', 'closed'), (2, '2026-10', 'reopened'), (3, '2026-10', 'closed')");
        $reports = new AdminReports($this->pdo, new CondominiumScope($this->pdo));
        $overview = $reports->overview($this->user(7), '2026-11-10');
        self::assertSame(['display_name' => 'Connect', 'support_email' => 'atendimento@adm.test', 'support_phone' => ''], $overview['administrator'],
            'Só o padrão da Connect; o valor de um condomínio não vale como dado da administradora.');
        self::assertSame(['condominiums' => 2, 'units' => 3, 'residents' => 1, 'users' => 3, 'bank_accounts' => 1, 'closed_previous_month' => 1, 'open_previous_month' => 1],
            $overview['summary']);
        self::assertSame(['2026-10', false], [$overview['previous_month'], $overview['global_access']]);

        $context = static fn (string $report, string $condominium, int $bytes): string => json_encode(['changed_fields' => ['report', 'condominium_id', 'bytes'],
            'changes' => ['report' => ['before' => '', 'after' => $report], 'condominium_id' => ['before' => '', 'after' => $condominium], 'bytes' => ['before' => '', 'after' => (string)$bytes]]]);
        $insert = $this->pdo->prepare('INSERT INTO system_audit_logs (id, users_id, action, entity, context_json, ip, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        foreach ([
            [1, 7, 'export', 'erp_reports', $context('ledger', '1', 100), '10.0.0.5', '2026-11-01 10:00:00'],
            [2, 8, 'export', 'erp_reports', $context('ledger', '3', 50), '10.0.0.6', '2026-11-02 10:00:00'],     // condomínio fora do escopo
            [3, 7, 'export', 'erp_reports', $context('income-statement', '', 30), '10.0.0.7', '2026-11-03 10:00:00'],   // sem condomínio, do próprio
            [4, 8, 'export', 'erp_reports', $context('ledger', '', 20), '10.0.0.8', '2026-11-04 10:00:00'],     // sem condomínio, de outro
            [5, 7, 'update', 'erp_reports', $context('ledger', '1', 1), null, '2026-11-05 10:00:00'],
            [6, 7, 'export', 'users', $context('x', '1', 1), null, '2026-11-05 10:00:00'],
            [7, 7, 'export', 'erp_reports', $context('ledger', '1', 1), null, '2026-09-01 10:00:00'],
        ] as $row) {
            $insert->execute($row);
        }
        $mine = $reports->sent($this->user(7), '2026-11-01', '2026-11-30');
        self::assertSame([3, 1], array_column($mine['rows'], 'id'));
        self::assertEquals(['report' => 'ledger', 'condominium_id' => 1, 'bytes' => 100, 'ip' => '10.0.0.*', 'user_name' => 'Ana Admin'],
            array_intersect_key($mine['rows'][1], array_flip(['report', 'condominium_id', 'bytes', 'ip', 'user_name'])));
        self::assertSame(['exports' => 2, 'bytes' => 130, 'by_report' => ['income-statement' => 1, 'ledger' => 1]], $mine['totals']);
        self::assertSame([4, 3, 2, 1], array_column($reports->sent($this->user(2), '2026-11-01', '2026-11-30')['rows'], 'id'), 'Acesso a todos: vê tudo.');
        self::assertSame([1], array_column($reports->sent($this->user(7), '2026-11-01', '2026-11-30', 'ledger')['rows'], 'id'));
        self::assertNull(AdminReports::exportContext('{quebrado')['condominium_id']);
        try {
            $reports->sent($this->user(7), '2026-11-30', '2026-11-01');
            self::fail('Aceitou período invertido.');
        } catch (InvalidArgumentException) {   // 422
            self::addToAssertionCount(1);
        }

        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Api/V1/ErpAdmin.php');
        foreach (["'erp.settings.view'", "'erp.audit.view'", "'erp.users.view', 'erp.users.manage'", "'erp.permissions.view', 'erp.permissions.manage'",
            'catch (CondominiumAccessDenied)', 'catch (RegistryForbidden)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $migration = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261109_0705_erp_users_permissions.sql');
        self::assertStringContainsString("WHERE r.slug IN ('developer','super_admin')", $migration, 'Fail-closed: só os papéis globais recebem.');
    }

    private function access(): ErpAccess
    {
        // Simula Access::can com a matriz da fixture (papel + exceções), como o Access real.
        return new ErpAccess($this->pdo, new CondominiumScope($this->pdo), function (string $permission, User $user): bool {
            $stmt = $this->pdo->prepare('SELECT r.slug, o.effect, EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id = r.id AND rp.permission_id = p.id) AS allowed
                FROM access_permissions p JOIN access_user_roles ur ON ur.user_id = :u JOIN access_roles r ON r.id = ur.role_id
                LEFT JOIN access_user_overrides o ON o.user_id = ur.user_id AND o.permission_id = p.id WHERE p.slug = :p');
            $stmt->execute(['u' => (int)$user->id, 'p' => $permission]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row !== false && ErpAccess::effective(true, (bool)$row['allowed'], $row['effect']);
        });
    }

    private function expectScope(callable $call, string $message = 'Respondeu fora do escopo.'): void
    {
        try {
            $call();
            self::fail($message);
        } catch (CondominiumAccessDenied) {   // 404
            self::addToAssertionCount(1);
        }
    }

    private function expectForbidden(callable $call, string $message = 'Permitiu.'): void
    {
        try {
            $call();
            self::fail($message);
        } catch (RegistryForbidden) {   // 403
            self::addToAssertionCount(1);
        }
    }

    private function user(int $id): User
    {
        $user = new User();
        $user->id = $id;
        return $user;
    }
}
