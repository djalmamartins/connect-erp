<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\DocumentTransitSettlement as Service;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Baixa em lote de malotes e avisos de recebimento (ADR 0216):
 *  POST /api/v1/document-transit/settle {condominium_id, on, pouch_ids?, notice_ids?, check?, outcome?, dry_run?}
 * Exige erp.access e, para cada lista preenchida, a permissão de ver e de gerenciar o cadastro (erp.pouches.* e
 * erp.delivery_notices.*), fail-closed; CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida, documento
 * já resolvido ou recusado pelo formulário = 422 (nada é gravado).
 */
final class DocumentTransitSettlement
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $needs = [];
        if (($body['pouch_ids'] ?? []) !== []) {
            $needs = ['erp.pouches.view', 'erp.pouches.manage'];
        }
        if (($body['notice_ids'] ?? []) !== []) {
            $needs = array_merge($needs, ['erp.delivery_notices.view', 'erp.delivery_notices.manage']);
        }
        foreach ($needs as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        try {
            $data = (new Service())->settle($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Baixa recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
