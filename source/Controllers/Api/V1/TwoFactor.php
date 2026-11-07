<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Support\Middleware\ApiAuthenticated;

/** POST /api/v1/auth/2fa/verify — conclui um login pendente de segundo fator. */
final class TwoFactor
{
    public function verify(): void
    {
        if (!ApiAuthenticated::validCsrf()) {
            JsonResponse::send(419, ['error' => ['code' => 'csrf_invalid', 'message' => 'Token CSRF ausente ou inválido.']]);
            return;
        }
        $pending = Auth::twoFactorPending();
        if (!$pending || $pending->mode !== 'verify') {
            JsonResponse::send(409, ['error' => ['code' => 'no_pending_challenge', 'message' => 'Não há verificação pendente.']]);
            return;
        }
        if (request_limit('api_2fa', 6, 300)) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_attempts', 'message' => 'Muitas tentativas. Aguarde alguns minutos.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $code = is_array($body) ? (string)($body['code'] ?? '') : '';
        $auth = new Auth();
        if (!$auth->completeTwoFactor($code)) {
            JsonResponse::send(422, ['error' => ['code' => 'invalid_code', 'message' => 'Código de verificação inválido.']]);
            return;
        }
        JsonResponse::send(200, ['data' => ['authenticated' => true]]);
    }
}
