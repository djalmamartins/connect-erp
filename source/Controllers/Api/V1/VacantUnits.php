<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\VacantUnits as Service;
use Source\Support\Access;

/**
 * Unidades vagas e o que ficou nelas (ADR 0270), somente leitura:
 *  GET /api/v1/reports/vacant-units?condominium_id=
 * Exige erp.access, erp.units.view, erp.residents.view, erp.visitors.view, erp.vehicles.view, erp.pets.view e
 * erp.entries.view (as dos cadastros e dos lançamentos que o relatório conta) e o condomínio no escopo (fail-closed: fora =
 * 404). Entrada inválida = 422. Não grava nem envia nada.
 */
final class VacantUnits
{
    public const PERMISSIONS = ['erp.access', 'erp.units.view', 'erp.residents.view', 'erp.visitors.view', 'erp.vehicles.view', 'erp.pets.view', 'erp.entries.view'];

    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        foreach (self::PERMISSIONS as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
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
