<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\OperationsSummary as Service;
use Source\Support\Access;

/**
 * Resumo operacional do mês para o síndico (ADR 0210), somente leitura:
 *  GET /api/v1/reports/operations-summary?condominium_id=&competence=AAAA-MM
 * Exige erp.access, erp.occurrences.view, erp.maintenances.view e erp.reservations.view (fail-closed: sem uma delas,
 * 403, em vez de um resumo pela metade). Condomínio fora do escopo = 404; entrada inválida = 422.
 */
final class OperationsSummary
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        foreach (['erp.access', 'erp.occurrences.view', 'erp.maintenances.view', 'erp.reservations.view'] as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['competence'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
