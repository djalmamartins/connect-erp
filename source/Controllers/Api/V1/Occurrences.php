<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use Source\Models\Auth;
use Source\Services\Erp\Occurrences as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Support\TicketError;
use Source\Support\Access;

/**
 * POST /api/v1/occurrences/{id}/ticket — abre o chamado da ocorrência na fila do Help Desk
 * (ADR 0034 e 0051). Exige erp.occurrences.manage. O cadastro em si é /api/v1/occurrences (motor).
 */
final class Occurrences
{
    public function ticket(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.occurrences.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $id = (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
        try {
            JsonResponse::send(201, ['data' => (new Service())->openTicket($user, $id)]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Ocorrência não encontrada no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'ticket_exists', 'message' => $exception->getMessage()]]);
        } catch (TicketError $exception) {
            JsonResponse::send(in_array($exception->getCode(), [403, 422], true) ? $exception->getCode() : 422, ['error' => ['code' => 'ticket_refused', 'field' => $exception->field, 'message' => $exception->getMessage()]]);
        }
    }
}
