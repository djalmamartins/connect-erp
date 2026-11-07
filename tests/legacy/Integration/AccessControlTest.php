<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Support\Access;

final class AccessControlTest extends TestCase
{
    public function testRolePermissionAllowsAndMissingPermissionDenies(): void
    {
        [$user, $permissionId] = $this->prepareAccess('manager', 'articles.manage', true);

        self::assertTrue(Access::can('articles.manage', $user));
        self::assertFalse(Access::can('settings.manage', $user));
        self::assertGreaterThan(0, $permissionId);
    }

    public function testUserOverrideTakesPrecedenceOverRole(): void
    {
        [$user, $permissionId] = $this->prepareAccess('manager', 'articles.manage', true);
        $this->pdo->prepare("INSERT INTO access_user_overrides (user_id,permission_id,effect) VALUES (?,?,'deny')")
            ->execute([$user->id, $permissionId]);
        Access::clear();

        self::assertFalse(Access::can('articles.manage', $user));

        $this->pdo->prepare("UPDATE access_user_overrides SET effect='allow' WHERE user_id=? AND permission_id=?")
            ->execute([$user->id, $permissionId]);
        Access::clear();
        self::assertTrue(Access::can('articles.manage', $user));
    }

    public function testDeveloperAlwaysHasRegisteredPermission(): void
    {
        [$user] = $this->prepareAccess('developer', 'logs.view', false);
        self::assertTrue(Access::can('logs.view', $user));
    }

    public function testDisabledModuleBlocksNonDeveloper(): void
    {
        [$user] = $this->prepareAccess('manager', 'studio.access', true);
        $this->pdo->exec('UPDATE settings SET access_studio=0 WHERE id=1');
        Access::clear();

        self::assertFalse(Access::can('studio.access', $user));
    }

    private function prepareAccess(string $roleSlug, string $permissionSlug, bool $roleAllowed): array
    {
        $userId = $this->createUser(['level' => $roleSlug === 'developer' ? 10 : 2]);
        $this->pdo->prepare('INSERT INTO access_roles (name,slug,level) VALUES (?,?,?)')
            ->execute([ucfirst($roleSlug), $roleSlug, $roleSlug === 'developer' ? 100 : 50]);
        $roleId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_permissions (name,slug,group_name) VALUES (?,?,?)')
            ->execute([$permissionSlug, $permissionSlug, 'Teste']);
        $permissionId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id,role_id) VALUES (?,?)')->execute([$userId, $roleId]);
        if ($roleAllowed) {
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id,permission_id) VALUES (?,?)')->execute([$roleId, $permissionId]);
        }
        return [(new User())->findById($userId), $permissionId];
    }
}
