<?php

declare(strict_types=1);

namespace Source\Services;

/**
 * Regras do reset de 2FA feito por administrador (issue #7, risco 4; ADR 0025).
 * Sem banco: o controller passa os dados já carregados e recebe o motivo da recusa.
 */
final class TwoFactorAdminReset
{
    public const PERMISSION = 'security.2fa.manage';

    /**
     * null quando o reset pode seguir; senão o código do erro da API.
     * - forbidden: sem security.2fa.manage
     * - self_reset: o próprio 2FA se desativa pela tela de segurança, com código
     * - not_found: usuário alvo não existe
     * - higher_level: o alvo tem nível legado maior que o de quem pede
     * - not_enabled: o alvo não tem 2FA ativo
     */
    public static function denial(int $actorId, int $actorLevel, bool $canManage, int $targetId, ?int $targetLevel, bool $targetEnabled): ?string
    {
        if (!$canManage) {
            return 'forbidden';
        }
        if ($targetId === $actorId) {
            return 'self_reset';
        }
        if ($targetId <= 0 || $targetLevel === null) {
            return 'not_found';
        }
        if ($targetLevel > $actorLevel) {
            return 'higher_level';
        }
        if (!$targetEnabled) {
            return 'not_enabled';
        }
        return null;
    }

    /** Status HTTP de cada recusa. */
    public static function status(string $denial): int
    {
        return match ($denial) {
            'forbidden', 'higher_level' => 403,
            'not_found' => 404,
            'not_enabled' => 409,
            default => 422,
        };
    }
}
