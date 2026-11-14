<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\InvoiceTypeOverview as Service;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Access;

/**
 * Situação dos tipos de faturas do condomínio (ADR 0296), somente leitura:
 *  GET /api/v1/reports/invoice-types?condominium_id=&months=
 * Exige erp.access, a permissão de ver de cada cadastro que entra na conta (tipos de faturas e plano de contas:
 * erp.invoice_types.view e erp.chart.view) e a de ver faturas (erp.invoices.view), e o condomínio no escopo
 * (fail-closed: fora = 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class InvoiceTypeOverview
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $allowed = Access::can('erp.access', $user) && Access::can('erp.invoices.view', $user);
        foreach (['invoice_types', 'chart'] as $slug) {
            $allowed = $allowed && Access::can((string)RegistryService::definition($slug)['permission'] . '.view', $user);
        }
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
