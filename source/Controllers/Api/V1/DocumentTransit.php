<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\DocumentTransit as Service;
use Source\Support\Access;

/**
 * Documentos em trânsito (ADR 0215), somente leitura:
 *  GET /api/v1/reports/document-transit?condominium_id=&as_of=AAAA-MM-DD&days=
 * Exige erp.access, erp.pouches.view e erp.delivery_notices.view (fail-closed). Condomínio fora do escopo = 404;
 * entrada inválida = 422. Não grava nem envia nada.
 */
final class DocumentTransit
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.pouches.view', $user) || !Access::can('erp.delivery_notices.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['as_of'] ?? null, $_GET['days'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
