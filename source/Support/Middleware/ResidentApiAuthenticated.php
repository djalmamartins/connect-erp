<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Resident\ResidentAppSessions;

/**
 * Middleware das rotas /api/v1/resident (ADR 0302): exige sessão autenticada (já elevada pelo 2FA quando
 * exigido) e token CSRF em métodos que alteram estado, como o ApiAuthenticated. O vínculo de morador é
 * conferido no controller (ResidentLinks), porque a resposta depende da unidade escolhida.
 * O aplicativo nativo (ADR 0303) entra com o token Bearer da sessão do aparelho, sem cookie nem CSRF.
 */
final class ResidentApiAuthenticated
{
    public function handle(mixed $router): bool
    {
        // App nativo (ADR 0303): "Authorization: Bearer <token>" vale no lugar da sessão. Sem cookie, sem CSRF; o
        // usuário fica só nesta requisição (Auth::actingAs). Token presente e inválido = 401, sem cair na sessão.
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if (is_string($header) && preg_match('/^\s*Bearer\s/i', $header) === 1) {
            $user = (new ResidentAppSessions())->authenticate(ResidentAppSessions::bearerFrom($header));
            if ($user === null) {
                JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Sua sessão no app terminou. Entre de novo.']]);
                return false;
            }
            Auth::actingAs($user);
            return true;
        }
        if (!Auth::user()) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return false;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !ApiAuthenticated::validCsrf()) {
            JsonResponse::send(419, ['error' => ['code' => 'csrf_invalid', 'message' => 'Token CSRF ausente ou inválido.']]);
            return false;
        }
        return true;
    }
}
