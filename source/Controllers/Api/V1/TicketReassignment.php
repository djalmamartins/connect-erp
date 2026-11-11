<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Support\TicketReassignment as Service;
use Source\Support\Access;

/**
 * Reatribuição em lote dos chamados de um responsável para outro (ADR 0227):
 *  POST /api/v1/tickets/reassign {condominium_id, from_user_id, to_user_id, reason, ticket_ids?, dry_run?}
 * Exige studio.access e support.manage, as mesmas da edição de chamados no Help Desk (fail-closed); CSRF pelo
 * ApiAuthenticated. Fora do escopo = 404; entrada inválida ou chamado recusado = 422 (nada é gravado). Nenhum aviso é
 * enviado.
 */
final class TicketReassignment
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('studio.access', $user) || !Access::can('support.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->reassign($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Reatribuição recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
