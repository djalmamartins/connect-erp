<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Directory\AccessDeniedSuggest;
use Source\Services\Directory\PeopleSuggest as Service;

/**
 * Sugestões de pessoa e e-mail ao digitar, para o campo padrão de todo o sistema (ADR 0687). Sessão no middleware
 * ApiAuthenticated; as fontes dependem das permissões de quem busca (ver o serviço).
 *  GET /api/v1/suggest/people?q=&kind=person|email&with=email&sources=residents,team&condominium_id=&unit_id=&limit=8
 *      {"data":[{"source":"resident"|"team","id","account_id","name","email","context","role"}]}
 * 401 = sem sessão; 403 = nenhuma fonte permitida; 422 = texto curto; 429 = limite de buscas.
 */
final class PeopleSuggest
{
    public function search(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        try {
            $data = (new Service())->search($user, $_GET);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (AccessDeniedSuggest $exception) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => $exception->getMessage()]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_requests', 'message' => $exception->getMessage()]]);
        }
    }
}
