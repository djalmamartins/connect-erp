<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\InvoiceEntryCleanup as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Contas a receber ainda ativas de faturas canceladas inativadas em lote (ADR 0401):
 *  POST /api/v1/invoices/deactivate-cancelled-entries {condominium_id, entry_ids?, reason, dry_run?}
 * Exige erp.access, as permissões de ver e de gerenciar faturas (erp.invoices.view e erp.invoices.manage, como no
 * cancelamento) e as de ver e de gerenciar lançamentos (erp.entries.view e erp.entries.manage); fail-closed. CSRF pelo
 * ApiAuthenticated. Fora do escopo = 404; entrada inválida ou conta recusada = 422 (nada é inativado); conta que muda de
 * situação durante o pedido = 409.
 */
final class InvoiceEntryCleanup
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $entries = (string)RegistryService::definition('entries')['permission'];
        $allowed = Access::can('erp.access', $user) && Access::can('erp.invoices.view', $user) && Access::can('erp.invoices.manage', $user)
            && Access::can($entries . '.view', $user) && Access::can($entries . '.manage', $user);
        if (!$allowed) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->deactivate($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Recusado: nenhuma conta foi inativada.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
