<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Regras puras do token de recuperação de senha (sem banco):
 * 32 bytes aleatórios em hex, apenas o SHA-256 é persistido, validade curta.
 */
final class PasswordResetToken
{
    public const TTL_SECONDS = 1800;
    private const PATTERN = '~^[a-f0-9]{64}$~';

    public static function generate(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function isWellFormed(string $token): bool
    {
        return (bool)preg_match(self::PATTERN, $token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function expiresAt(?int $now = null, int $ttl = self::TTL_SECONDS): string
    {
        return date('Y-m-d H:i:s', ($now ?? time()) + $ttl);
    }

    public static function isExpired(string $expiresAt, ?int $now = null): bool
    {
        $expires = strtotime($expiresAt);
        return $expires === false || ($now ?? time()) >= $expires;
    }
}
