<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Team\TeamAppSessions;

/**
 * Só para GET /api/v1/ticket-attachments/{id} (ADR 0339 e 0660): o anexo do chamado abre com a sessão do navegador,
 * com o token do app do morador ou com o token dos apps da equipe. Quem pode ver cada anexo continua decidido no
 * controller (TicketAttachmentAccess). Rota só de leitura, então sem CSRF.
 */
final class AppAttachmentApiAuthenticated
{
    public function handle(mixed $router): bool
    {
        $header = ResidentAppSessions::authorizationHeader();
        if (is_string($header) && preg_match('/^\s*Bearer\s/i', $header) === 1) {
            $token = ResidentAppSessions::bearerFrom($header);
            $user = (new ResidentAppSessions())->authenticate($token) ?? TeamAppSessions::make()->authenticate($token);
            if ($user === null) {
                JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Sua sessão no app terminou. Entre de novo.']]);
                return false;
            }
            Auth::actingAs($user);
            return true;
        }
        return (new ResidentApiAuthenticated())->handle($router);
    }
}
