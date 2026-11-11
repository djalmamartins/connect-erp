<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\GatehouseTraffic as Service;
use Source\Support\Access;

/**
 * Movimento da portaria do condomínio no período (ADR 0230), somente leitura:
 *  GET /api/v1/reports/gatehouse-traffic?condominium_id=&from=AAAA-MM-DD&to=AAAA-MM-DD
 * Exige erp.access, erp.access_logs.view e erp.visit_forecasts.view, as mesmas do registro de acessos e da previsão de
 * visita, e o condomínio no escopo (fail-closed: fora = 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class GatehouseTraffic
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.access_logs.view', $user) || !Access::can('erp.visit_forecasts.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
