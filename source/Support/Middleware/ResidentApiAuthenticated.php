<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Models\Auth;

/**
 * Middleware das rotas /api/v1/resident (ADR 0302): exige sessão autenticada (já elevada pelo 2FA quando
 * exigido) e token CSRF em métodos que alteram estado, como o ApiAuthenticated. O vínculo de morador é
 * conferido no controller (ResidentLinks), porque a resposta depende da unidade escolhida.
 * Ponto único para o login por token do aplicativo nativo (ADR 0303), que entra aqui.
 */
final class ResidentApiAuthenticated
{
    public function handle(mixed $router): bool
    {
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
