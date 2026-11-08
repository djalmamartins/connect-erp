<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;

/** Isolamento por condomínio (ADR-002): critério obrigatório para fechar a Fase 1. */
final class CondominiumScopeTest extends TestCase
{
    public function testUserSeesOnlyLinkedActiveCondominiums(): void
    {
        [$alpha, $beta, $inactive] = [$this->condominium('Alpha'), $this->condominium('Beta'), $this->condominium('Gama', 'inactive')];
        $user = $this->user();
        $scope = new CondominiumScope($this->pdo);
        $scope->grant((int)$user->id, $alpha);
        $scope->grant((int)$user->id, $inactive);

        self::assertSame([$alpha], $scope->allowedIds($user));
        self::assertSame(['Alpha'], array_column($scope->allowed($user), 'name'));
        self::assertTrue($scope->canAccess($user, $alpha));
        self::assertFalse($scope->canAccess($user, $beta), 'Condomínio sem vínculo não pode ser acessado.');
        self::assertFalse($scope->canAccess($user, $inactive), 'Condomínio inativo fica fora do escopo.');
    }

    public function testAssertAccessThrowsAndLogsOutsideScope(): void
    {
        $other = $this->condominium('Outro');
        $user = $this->user();

        try {
            (new CondominiumScope($this->pdo))->assertAccess($user, $other);
            self::fail('Acesso fora do escopo foi permitido.');
        } catch (CondominiumAccessDenied) {
            self::addToAssertionCount(1);
        }
        $logged = (int)$this->pdo->query("SELECT COUNT(*) FROM app_log WHERE code = 'CONDOMINIUM_SCOPE_DENIED'")->fetchColumn();
        self::assertSame(1, $logged);
    }

    public function testGlobalAccessRequiresExplicitPermissionNotLevel(): void
    {
        $alpha = $this->condominium('Alpha');
        $beta = $this->condominium('Beta');
        $highLevel = $this->user(10);
        $scope = new CondominiumScope($this->pdo);

        self::assertFalse($scope->hasGlobalAccess($highLevel), 'Nível alto sem permissão não dá acesso global.');
        self::assertSame([], $scope->allowedIds($highLevel));

        $manager = $this->user();
        $this->assignRole((int)$manager->id, 'manager', true);
        self::assertTrue($scope->hasGlobalAccess($manager));
        self::assertEqualsCanonicalizing([$alpha, $beta], $scope->allowedIds($manager));
    }

    public function testUserDenyOverrideRemovesGlobalAccess(): void
    {
        $this->condominium('Alpha');
        $user = $this->user();
        $permissionId = $this->assignRole((int)$user->id, 'manager', true);
        $this->pdo->prepare("INSERT INTO access_user_overrides (user_id, permission_id, effect) VALUES (?, ?, 'deny')")
            ->execute([$user->id, $permissionId]);

        $scope = new CondominiumScope($this->pdo);
        self::assertFalse($scope->hasGlobalAccess($user));
        self::assertSame([], $scope->allowedIds($user));
    }

    public function testRevokeRemovesAccessAndInvalidInputsAreDenied(): void
    {
        $alpha = $this->condominium('Alpha');
        $user = $this->user();
        $scope = new CondominiumScope($this->pdo);
        $scope->grant((int)$user->id, $alpha);
        $scope->grant((int)$user->id, $alpha);
        $scope->revoke((int)$user->id, $alpha);

        self::assertFalse($scope->canAccess($user, $alpha));
        self::assertFalse($scope->canAccess($user, 0));
        self::assertFalse($scope->canAccess(null, $alpha));
        self::assertSame([], $scope->allowed(null));
    }

    private function condominium(string $name, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, $status]);
        return (int)$this->pdo->lastInsertId();
    }

    private function user(int $level = 2): User
    {
        return (new User())->findById($this->createUser(['level' => $level]));
    }

    /** Cria o papel e devolve o id da permissão erp.condominiums.all. */
    private function assignRole(int $userId, string $roleSlug, bool $withGlobal): int
    {
        $this->pdo->prepare('INSERT IGNORE INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([ucfirst($roleSlug), $roleSlug]);
        $roleId = (int)$this->pdo->query('SELECT id FROM access_roles WHERE slug = ' . $this->pdo->quote($roleSlug))->fetchColumn();
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos os condomínios', ?, 'ERP')")
            ->execute([CondominiumScope::ALL_PERMISSION]);
        $permissionId = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        if ($withGlobal) {
            $this->pdo->prepare('INSERT IGNORE INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$roleId, $permissionId]);
        }
        return $permissionId;
    }
}
