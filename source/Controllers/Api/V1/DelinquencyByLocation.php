<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\DelinquencyByLocation as Service;
use Source\Support\Access;

/**
 * Inadimplência por bloco ou andar (ADR 0222), somente leitura:
 *  GET /api/v1/reports/delinquency-by-location?condominium_id=&by=block|floor&as_of=AAAA-MM-DD
 * Exige erp.access e erp.agreements.view, as mesmas do relatório de inadimplentes (fail-closed). Condomínio fora do
 * escopo = 404; entrada inválida = 422. Não grava nem envia nada.
 */
final class DelinquencyByLocation
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.agreements.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null, $_GET['by'] ?? null, $_GET['as_of'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
