<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BatchReversal as Service;
use Source\Services\Erp\BatchSettlementRejected;
use Source\Support\Access;

/**
 * Estorno em lote da baixa (ADR 0192):
 *  POST /api/v1/entries/unsettle {condominium_id, entry_ids[], reason, dry_run?}
 * Exige erp.access, erp.entries.view e erp.entries.manage; CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada
 * inválida = 422; alguma conta que não pode ser estornada = 422 com problems (nada é gravado). Auditado pelo service.
 */
final class BatchReversal
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->reverse($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (BatchSettlementRejected $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'reversal_rejected', 'message' => $exception->getMessage(), 'problems' => $exception->problems]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
