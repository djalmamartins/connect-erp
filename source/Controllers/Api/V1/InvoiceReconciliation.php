<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\InvoiceReconciliation as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;

/**
 * Conferência entre as faturas e as contas a receber (ADR 0400), somente leitura:
 *  GET /api/v1/reports/invoice-reconciliation?condominium_id=&months=
 * Exige erp.access, a permissão de ver faturas (erp.invoices.view) e a de ver lançamentos (erp.entries.view), e o
 * condomínio no escopo (fail-closed: fora = 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class InvoiceReconciliation
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $allowed = Access::can('erp.access', $user) && Access::can('erp.invoices.view', $user)
            && Access::can((string)RegistryService::definition('entries')['permission'] . '.view', $user);
        if (!$allowed) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['months'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
