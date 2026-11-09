<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;

/**
 * Logout em todos os hosts (ADR 0035). erp.<domínio>, studio.<domínio> e o Help Desk têm cookies
 * de sessão separados; ao sair em um deles, revokeAll() grava o instante e isRevoked() faz as
 * sessões iniciadas antes dele deixarem de valer nos outros.
 *
 * Se a tabela faltar ou o banco falhar, registra log e não derruba ninguém (falha aberta: o
 * logout do host atual continua valendo).
 */
final class SessionRevocation
{
    /** @var array<int, int|null> revoked_at por usuário, cache do pedido */
    private static array $cache = [];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    public static function nowMs(): int
    {
        return (int)floor(microtime(true) * 1000);
    }

    public function revokeAll(int $userId, ?int $nowMs = null): void
    {
        $nowMs ??= self::nowMs();
        try {
            $pdo = $this->pdo();
            $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            $sql = $driver === 'sqlite'
                ? 'INSERT INTO user_session_revocations (user_id, revoked_at) VALUES (:user, :at) ON CONFLICT(user_id) DO UPDATE SET revoked_at = excluded.revoked_at'
                : 'INSERT INTO user_session_revocations (user_id, revoked_at) VALUES (:user, :at) ON DUPLICATE KEY UPDATE revoked_at = VALUES(revoked_at)';
            $pdo->prepare($sql)->execute(['user' => $userId, 'at' => $nowMs]);
            self::$cache[$userId] = $nowMs;
        } catch (\Throwable $error) {
            $this->unavailable($error, $userId);
        }
    }

    /** true quando a sessão começou ($authAtMs) antes ou no instante do último logout global. */
    public function isRevoked(int $userId, ?int $authAtMs): bool
    {
        if (!array_key_exists($userId, self::$cache)) {
            try {
                $stmt = $this->pdo()->prepare('SELECT revoked_at FROM user_session_revocations WHERE user_id = :user');
                $stmt->execute(['user' => $userId]);
                $value = $stmt->fetchColumn();
                self::$cache[$userId] = $value === false ? null : (int)$value;
            } catch (\Throwable $error) {
                $this->unavailable($error, $userId);
                return false;
            }
        }
        $revokedAt = self::$cache[$userId];
        return $revokedAt !== null && (int)($authAtMs ?? 0) <= $revokedAt;
    }

    /** Para testes e para trocas de usuário no mesmo processo. */
    public static function clearCache(): void
    {
        self::$cache = [];
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }

    private function unavailable(\Throwable $error, int $userId): void
    {
        AppLogger::log('error', 'Logout em todos os hosts indisponível', [
            'event_type' => 'session_revocation_unavailable', 'code' => 'SESSION_REVOCATION_UNAVAILABLE',
            'users_id' => $userId, 'error' => $error->getMessage(), 'status' => 'open',
        ], 'authentication');
    }
}
