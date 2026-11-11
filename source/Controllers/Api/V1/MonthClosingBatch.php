<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\MonthClosingBatch as Service;
use Source\Services\Erp\MonthClosingBatchRefused;
use Source\Support\Access;

/**
 * Fechamento do mês em lote (ADR 0205):
 *  POST /api/v1/closings/batch {competence, condominium_ids[], allow_warnings?, dry_run?}
 * Exige erp.access, erp.closing.view e erp.closing.manage; CSRF pelo ApiAuthenticated. Um condomínio fora do escopo =
 * 404; entrada inválida = 422; condomínio recusado na conferência = 422 com refused (nada é fechado).
 */
final class MonthClosingBatch
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || !Access::can('erp.closing.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->close($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (MonthClosingBatchRefused $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'closing_refused', 'message' => $exception->getMessage(), 'refused' => $exception->refused]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
