<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AnnouncementRetry as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Envios com falha dos comunicados publicados de volta à fila, em lote (ADR 0279):
 *  POST /api/v1/announcements/retry-failed {condominium_id, announcement_ids?, reason, dry_run?}
 * Exige erp.access, erp.announcements.view e erp.announcements.manage (as do cancelamento de comunicado) e
 * erp.notifications.manage (a do reenvio na tela da fila);
 * fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida ou comunicado recusado = 422 (nada volta
 * à fila). Nada é enviado aqui: o worker da fila envia, com as travas de ambiente de sempre.
 */
final class AnnouncementRetry
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.announcements.view', $user) || !Access::can('erp.announcements.manage', $user)
            || !Access::can('erp.notifications.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->retry($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Recusado: nenhum envio voltou à fila.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
