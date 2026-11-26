<?php

declare(strict_types=1);

namespace Source\Services\Team;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Resident\ResidentTwoFactor;
use Source\Services\TwoFactorService;
use Source\Support\Access;

/**
 * Sessões dos apps da equipe no celular (SAC e Meu Dia, ADR 0660). Mesma regra do app do morador (ADR 0303):
 * e-mail ou CPF e senha, mesmo limite de tentativas, 2FA por desafio de uso único, token Bearer de 256 bits guardado
 * só como sha256, 90 dias, cai com 30 dias sem uso e com o logout global. Diferenças: o público é 'team' e só entra
 * quem tem erp.access (a mesma regra da API da equipe). 2FA obrigatório e não configurado = 409 (configurar na web).
 */
final class TeamAppSessions
{
    public const REFUSAL = ['code' => 'not_team', 'message' => 'Esta conta não tem acesso ao ERP. Fale com a administração.'];

    public static function make(?PDO $pdo = null, ?callable $isTeam = null): ResidentAppSessions
    {
        $pdo ??= Connect::getInstance();
        return new ResidentAppSessions(
            $pdo,
            new TwoFactorService($pdo),
            new LoginThrottle($pdo),
            $isTeam ?? static fn (User $user): bool => Access::can('erp.access', $user),
            static fn (int $userId): bool => ResidentTwoFactor::enforcedFor($userId, $pdo),
            'team',
            self::REFUSAL,
        );
    }
}
