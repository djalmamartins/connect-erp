<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\VisitorUnitSituation as Service;
use Source\Support\Access;

/**
 * Visitantes do condomínio pela situação da unidade (ADR 0266), somente leitura:
 *  GET /api/v1/reports/visitor-units?condominium_id=
 * Exige erp.access, erp.visitors.view (a do cadastro de visitantes) e erp.residents.view (o morador ativo vem do cadastro
 * de moradores), e o condomínio no escopo (fail-closed: fora = 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class VisitorUnitSituation
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.visitors.view', $user) || !Access::can('erp.residents.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['condominium_id'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
