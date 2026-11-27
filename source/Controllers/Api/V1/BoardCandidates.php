<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BoardCandidates as Service;
use Source\Support\Access;

/**
 * Moradores do prédio e síndicos profissionais para escolher o síndico e o conselho (ADR 0683), somente leitura:
 *  GET /api/v1/board-members/candidates?condominium_id=
 *  {"data":{"condominium_id","residents_allowed","residents":[{"id","name","document","document_formatted","email","phone",
 *   "unit_id","unit_label","relationship","relationship_label"}],"professionals":[{"id","name","document",
 *   "document_formatted","email","phone","contact_name"}]}}
 * Exige erp.access e erp.board.manage (é para cadastrar); os moradores só com erp.residents.view. Condomínio fora do
 * escopo = 404; sem condominium_id = 422. Não grava nada.
 */
final class BoardCandidates
{
    public function index(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.board.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->list($user, $_GET['condominium_id'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
