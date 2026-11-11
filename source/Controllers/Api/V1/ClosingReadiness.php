<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ClosingReadiness as Service;
use Source\Support\Access;

/**
 * Conferência antes de fechar o mês (AP-030, ADR 0181), somente leitura:
 *  GET /api/v1/closings/readiness?condominium_id=&competence=AAAA-MM
 * Exige erp.closing.view, erp.entries.view e erp.chart.view (usa o Balanço). Fora do escopo = 404; sem condomínio ou
 * competência inválida = 422. Não fecha nada: o fechamento continua em POST /api/v1/closings.
 */
final class ClosingReadiness
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || !Access::can('erp.entries.view', $user)
            || !Access::can('erp.chart.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            $data = (new Service())->check($user, $condominium === false || $condominium === null ? null : (int)$condominium, $_GET['competence'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
