<?php

declare(strict_types=1);

namespace Source\Support\Middleware;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Resident\ResidentAppSessions;
use Source\Services\Talk\TalkTokens;
use Source\Support\AppLogger;
use Source\Support\ClientIp;

/**
 * Middleware das rotas /api/v1/talk (ADR 0308). O Connect Talk é um servidor: entra SÓ com o token de integração
 * ("Authorization: Bearer ctk_..."), sem sessão, sem cookie e sem CSRF. Sessão de navegador não vale aqui.
 * O usuário do token fica só nesta requisição (Auth::actingAs), para a auditoria e para o escopo de condomínios.
 * Lista de IPs opcional em TALK_ALLOWED_IPS; falhas de token contam no limite por IP (429).
 */
final class TalkApiAuthenticated
{
    public function handle(mixed $router): bool
    {
        header('Cache-Control: no-store');
        $ip = ClientIp::resolve();
        if (!TalkTokens::ipAllowed($ip, (string)(getenv('TALK_ALLOWED_IPS') ?: ''))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Endereço não autorizado para a integração.']]);
            return false;
        }
        $tokens = new TalkTokens();
        if ($tokens->blocked($ip)) {
            header('Retry-After: 900');
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => 'Muitas tentativas com token inválido. Tente mais tarde.']]);
            return false;
        }
        $user = $tokens->authenticate(TalkTokens::bearerFrom(ResidentAppSessions::authorizationHeader()));
        if ($user === null) {
            $tokens->fail($ip);
            AppLogger::log('notice', 'Token do Connect Talk recusado', ['event_type' => 'talk_token_rejected', 'origin' => 'talk'], 'authorization');
            header('WWW-Authenticate: Bearer');
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Token de integração ausente ou inválido.']]);
            return false;
        }
        Auth::actingAs($user);
        return true;
    }
}
