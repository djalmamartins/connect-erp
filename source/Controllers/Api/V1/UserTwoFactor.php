<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\Session\AppLog;
use Source\Models\User;
use Source\Services\TwoFactorAdminReset;
use Source\Services\TwoFactorService;
use Source\Services\TwoFactorNotice;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Audit;

/** DELETE /api/v1/users/{id}/2fa — reset do 2FA de outro usuário (ADR 0025). */
final class UserTwoFactor
{
    private const MESSAGES = [
        'forbidden' => 'Sem permissão.',
        'self_reset' => 'Para desativar o seu próprio 2FA, use a tela de segurança.',
        'not_found' => 'Usuário não encontrado.',
        'higher_level' => 'Sem permissão.',
        'not_enabled' => 'O usuário não tem autenticação em dois fatores ativa.',
    ];

    public function reset(array $data): void
    {
        $actor = Auth::user();
        $targetId = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        $canManage = Access::can(TwoFactorAdminReset::PERMISSION, $actor);
        $target = $canManage && $targetId > 0 ? (new User())->findById($targetId) : null;
        $twoFactor = new TwoFactorService();
        $denial = TwoFactorAdminReset::denial(
            (int)$actor->id, (int)$actor->level, $canManage, $targetId,
            $target ? (int)$target->level : null,
            $target ? $twoFactor->isEnabled($targetId) : false
        );
        if ($denial !== null) {
            if ($denial === 'forbidden' || $denial === 'higher_level') {
                AppLogger::log('warning', 'Reset de 2FA negado', [
                    'event_type' => 'authorization_denied', 'code' => 'TWO_FACTOR_RESET_DENIED',
                    'actor_id' => (int)$actor->id, 'target_id' => $targetId, 'reason' => $denial, 'status' => 'resolved',
                ], 'authorization');
            }
            JsonResponse::send(TwoFactorAdminReset::status($denial), ['error' => ['code' => $denial, 'message' => self::MESSAGES[$denial]]]);
            return;
        }
        if (request_limit('api_2fa_reset', 10, 300)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_attempts', 'message' => 'Muitas tentativas. Aguarde alguns minutos.']]);
            return;
        }
        $twoFactor->disable($targetId, (int)$actor->id);
        Audit::record('delete', 'user_two_factor', $targetId, ['enabled' => true], ['enabled' => false]);
        (new AppLog())->register((int)$actor->id, "Redefiniu o 2FA do usuário #{$targetId}");
        (new TwoFactorNotice())->send($target, TwoFactorNotice::RESET_BY_ADMIN);
        JsonResponse::send(200, ['data' => ['user_id' => $targetId, 'two_factor_enabled' => false]]);
    }
}
