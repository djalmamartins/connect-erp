<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Core\Session;
use Source\Models\Auth;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Team\TeamAppSessions;

/**
 * Middleware MovesCode Router para /api/v1: exige sessão autenticada (já
 * elevada pelo 2FA quando exigido) e token CSRF em métodos que alteram estado.
 * Os apps da equipe no celular (SAC e Meu Dia, ADR 0660) entram com o token Bearer da sessão do aparelho, no lugar
 * da sessão: sem cookie e sem CSRF; o usuário fica só nesta requisição (Auth::actingAs). As permissões e o escopo de
 * condomínio de cada rota continuam os mesmos.
 */
final class ApiAuthenticated
{
    public function handle(mixed $router): bool
    {
        // Token presente e inválido (inclusive token de morador) = 401, sem cair na sessão do navegador.
        $header = ResidentAppSessions::authorizationHeader();
        if (is_string($header) && preg_match('/^\s*Bearer\s/i', $header) === 1) {
            $user = TeamAppSessions::make()->authenticate(ResidentAppSessions::bearerFrom($header));
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
