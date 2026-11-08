<?php

namespace Source\Support;

use Source\Core\Connect;
use Source\Models\User;

final class Access
{
    /**
     * Prefixos que nunca caem no fallback legado por nível (A3, CONNECT_ERP_V1.md C9):
     * permissão não cadastrada ou usuário sem papel resulta em acesso negado.
     */
    public const STRICT_PREFIXES = ['erp.', 'api.', 'security.'];

    private static array $cache = [];

    public static function can(string $permission, ?User $user = null): bool
    {
        if (!$user || !$user->id) { return false; }
        $key = $user->id . ":" . $permission;
        if (array_key_exists($key, self::$cache)) { return self::$cache[$key]; }
        try {
            $pdo = Connect::getInstance();
            $stmt = $pdo->prepare("SELECT r.slug AS role_slug, o.effect,
                EXISTS(SELECT 1 FROM access_role_permissions rp WHERE rp.role_id=r.id AND rp.permission_id=p.id) AS role_allowed
                FROM access_permissions p
                JOIN access_user_roles ur ON ur.user_id=:user
                JOIN access_roles r ON r.id=ur.role_id
                LEFT JOIN access_user_overrides o ON o.user_id=ur.user_id AND o.permission_id=p.id
                WHERE p.slug=:permission LIMIT 1");
            $stmt->execute(["user" => $user->id, "permission" => $permission]);
            $access = $stmt->fetch();
            if (!$access) { return self::$cache[$key] = self::fallback($permission, (int)$user->level); }
            if ($access->role_slug === "developer") { return self::$cache[$key] = true; }
            if (!self::moduleEnabled($permission)) { return self::$cache[$key] = false; }
            if ($access->effect === "deny") { return self::$cache[$key] = false; }
            if ($access->effect === "allow") { return self::$cache[$key] = true; }
            return self::$cache[$key] = (bool)$access->role_allowed;
        } catch (\Throwable $exception) {
            AppLogger::exception($exception, 'authorization', ['event_type' => 'permission_check_failed', 'permission' => $permission, 'users_id' => $user->id]);
            // Falha na checagem sempre nega e não entra no cache: não libera pelo nível
            // legado nem fixa a negação por uma indisponibilidade transitória.
            return false;
        }
    }

    public static function role(?User $user): ?object
    {
        if (!$user) { return null; }
        try {
            $stmt = Connect::getInstance()->prepare("SELECT r.* FROM access_roles r JOIN access_user_roles ur ON ur.role_id=r.id WHERE ur.user_id=:user LIMIT 1");
            $stmt->execute(["user" => $user->id]);
            return $stmt->fetch() ?: null;
        } catch (\Throwable $exception) { AppLogger::exception($exception, 'authorization', ['event_type' => 'role_lookup_failed', 'users_id' => $user->id]); return null; }
    }

    public static function clear(?int $userId = null): void
    {
        if ($userId === null) { self::$cache = []; return; }
        foreach (array_keys(self::$cache) as $key) { if (str_starts_with($key, $userId . ":")) unset(self::$cache[$key]); }
    }

    public static function isStrict(string $permission): bool
    {
        foreach (self::STRICT_PREFIXES as $prefix) {
            if (str_starts_with($permission, $prefix)) { return true; }
        }
        return false;
    }

    /**
     * Decisão quando não há registro de permissão ou papel para o usuário: prefixos
     * estritos negam; as demais permissões mantêm o legado do Studio (nível >= 5).
     */
    public static function fallback(string $permission, int $level): bool
    {
        if (self::isStrict($permission)) { return false; }
        return $level >= 5;
    }

    private static function moduleEnabled(string $permission): bool
    {
        $column = ["studio.access" => "access_studio", "erp.access" => "access_erp", "app.access" => "access_app"][$permission] ?? null;
        if (!$column) { return true; }
        try { return (bool)Connect::getInstance()->query("SELECT {$column} FROM settings LIMIT 1")->fetchColumn(); }
        catch (\Throwable $exception) { AppLogger::exception($exception, 'authorization', ['event_type' => 'module_access_check_failed', 'permission' => $permission]); return false; }
    }
}
