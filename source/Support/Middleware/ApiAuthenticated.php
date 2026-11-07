<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Core\Session;
use Source\Models\Auth;

/**
 * Middleware MovesCode Router para /api/v1: exige sessão autenticada (já
 * elevada pelo 2FA quando exigido) e token CSRF em métodos que alteram estado.
 */
final class ApiAuthenticated
{
    public function handle(mixed $router): bool
    {
        if (!Auth::user()) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return false;
        }
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if (!in_array($method, ['GET', 'HEAD', 'OPTIONS'], true) && !self::validCsrf()) {
            JsonResponse::send(419, ['error' => ['code' => 'csrf_invalid', 'message' => 'Token CSRF ausente ou inválido.']]);
            return false;
        }
        return true;
    }

    public static function validCsrf(): bool
    {
        $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        $expected = (string)((new Session())->csrf_token ?? '');
        return $token !== '' && $expected !== '' && hash_equals($expected, $token);
    }
}
