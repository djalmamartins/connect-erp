<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\MeterReadingReview as Service;
use Source\Support\Access;

/**
 * Conferência das leituras de consumo de um mês (ADR 0220), somente leitura:
 *  GET /api/v1/reports/meter-reading-review?condominium_id=&competence=AAAA-MM&factor=
 * Exige erp.access e erp.meter_readings.view, as mesmas da lista de leituras (fail-closed). Condomínio fora do escopo =
 * 404; entrada inválida = 422. Não grava nem envia nada.
 */
final class MeterReadingReview
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.meter_readings.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['competence'] ?? null, $_GET['factor'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
