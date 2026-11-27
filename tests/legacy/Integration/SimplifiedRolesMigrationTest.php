<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Support\Access;

/** ADR 0682: papéis simplificados e migração das contas antigas sem perder acesso. */
final class SimplifiedRolesMigrationTest extends TestCase
{
    private function migrate(): void
    {
        $this->pdo->exec((string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261127_2030_simplified_roles.sql'));
        Access::clear();
    }

    private function role(string $slug): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM access_roles WHERE slug = ?');
        $stmt->execute([$slug]);
        return (int)$stmt->fetchColumn();
    }

    private function userWith(string $slug, string $email): User
    {
        $id = $this->createUser(['email' => $email, 'level' => 5]);
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $this->role($slug)]);
        $user = new User();
        $user->id = $id;
        $user->level = 5;
        return $user;
    }

    /** @return list<string> */
    private function permissionsOf(string $slug): array
    {
        $stmt = $this->pdo->prepare('SELECT p.slug FROM access_role_permissions rp JOIN access_roles r ON r.id = rp.role_id
            JOIN access_permissions p ON p.id = rp.permission_id WHERE r.slug = ? ORDER BY p.slug');
        $stmt->execute([$slug]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->registerMigrationPermissions();
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level, description) VALUES
            ('Desenvolvedor', 'developer', 100, ''), ('Super administrador', 'super_admin', 90, ''), ('Administrador do cliente', 'client_admin', 70, ''),
            ('Gerente', 'manager', 50, ''), ('Operador', 'operator', 30, ''), ('Usuário', 'user', 10, ''), ('Imobiliária parceira', 'erp_partner', 1, '')");
        // Antes: o operador entrava no Studio e editava artigos; o administrador do cliente tinha users.manage.
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p
            ON p.slug IN ('studio.access','articles.manage','media.manage','erp.access') WHERE r.slug = 'operator'");
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p
            ON p.slug IN ('users.manage','settings.manage','erp.access') WHERE r.slug = 'client_admin'");
    }

    public function testRolesAreRenamedAndOldAccountsKeepTheirAccess(): void
    {
        $admin = $this->userWith('client_admin', 'admin-antigo@example.invalid');
        $operator = $this->userWith('operator', 'operador@example.invalid');
        $this->migrate();
        $this->migrate();   // idempotente

        self::assertSame(0, $this->role('client_admin'), 'O papel antigo sai depois de mover as contas.');
        self::assertSame('super_admin', Access::role($admin)->slug);
        self::assertTrue(Access::can('users.manage', $admin));
        self::assertTrue(Access::can('erp.condominiums.all', $admin));
        self::assertSame(10, (int)$this->pdo->query('SELECT level FROM users WHERE id = ' . (int)$admin->id)->fetchColumn());

        $names = $this->pdo->query('SELECT slug, name FROM access_roles ORDER BY level DESC')->fetchAll(\PDO::FETCH_KEY_PAIR);
        self::assertSame(['developer' => 'Desenvolvedor', 'super_admin' => 'Administrador global', 'manager' => 'Gerente', 'financial' => 'Financeiro',
            'operator' => 'Operador', 'attendant' => 'Atendente', 'user' => 'Morador', 'erp_partner' => 'Imobiliária'], $names);

        self::assertTrue(Access::can('erp.units.view', $operator));
        self::assertTrue(Access::can('erp.meter_readings.manage', $operator));
        self::assertTrue(Access::can('support.manage', $operator));
        self::assertFalse(Access::can('studio.access', $operator), 'Operador não entra mais no Studio.');
        self::assertFalse(Access::can('erp.entries.view', $operator), 'Operador sem financeiro.');
        self::assertFalse(Access::can('erp.management_fees.view', $operator));
        self::assertSame(2, (int)$this->pdo->query('SELECT level FROM users WHERE id = ' . (int)$operator->id)->fetchColumn());
    }

    public function testEachTeamRoleGetsItsOwnSet(): void
    {
        $this->migrate();
        $manager = $this->permissionsOf('manager');
        self::assertContains('erp.closing.manage', $manager);
        self::assertContains('erp.units.manage', $manager);
        self::assertContains('erp.users.view', $manager);
        foreach (['erp.users.manage', 'erp.permissions.manage', 'erp.settings.manage', 'erp.condominiums.all', 'proposals.manage', 'users.manage', 'system.manage'] as $slug) {
            self::assertNotContains($slug, $manager, "Gerente sem {$slug}");
        }

        $financial = $this->permissionsOf('financial');
        self::assertContains('erp.bank_charges.manage', $financial);
        self::assertContains('erp.closing.manage', $financial);
        self::assertContains('erp.units.view', $financial);
        self::assertNotContains('erp.units.manage', $financial);
        self::assertNotContains('support.manage', $financial);
        self::assertNotContains('studio.access', $financial);

        $attendant = $this->permissionsOf('attendant');
        self::assertContains('support.manage', $attendant);
        self::assertContains('erp.bank_charges.view', $attendant);
        self::assertContains('erp.deliveries.manage', $attendant);
        self::assertNotContains('erp.bank_charges.manage', $attendant);
        self::assertNotContains('erp.residents.manage', $attendant);
        self::assertNotContains('erp.inspections.perform', $attendant);

        self::assertSame([], array_values(array_filter($this->permissionsOf('user'), static fn (string $p): bool => str_starts_with($p, 'erp.'))), 'Morador fora do ERP.');
        self::assertSame(array_map('strval', $this->pdo->query('SELECT slug FROM access_permissions ORDER BY slug')->fetchAll(\PDO::FETCH_COLUMN)), $this->permissionsOf('super_admin'));
    }

    public function testRollbackBringsTheOldRolesBackAndKeepsEveryAccount(): void
    {
        $this->migrate();
        $financial = $this->userWith('financial', 'financeiro@example.invalid');
        $this->pdo->exec((string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261127_2030_simplified_roles.down.sql'));
        Access::clear();
        self::assertGreaterThan(0, $this->role('client_admin'));
        self::assertSame(0, $this->role('financial'));
        self::assertSame('operator', Access::role($financial)->slug, 'Quem era Financeiro volta para Operador, sem ficar sem papel.');
        self::assertTrue(Access::can('studio.access', $financial));
        $this->migrate();
        self::assertSame(0, $this->role('client_admin'));
        self::assertGreaterThan(0, $this->role('financial'));
    }
}
