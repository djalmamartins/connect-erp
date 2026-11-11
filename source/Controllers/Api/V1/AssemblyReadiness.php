<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use Source\Models\Auth;
use Source\Services\Erp\AssemblyReadiness as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Conferência antes da assembleia (ADR 0211), somente leitura:
 *  GET /api/v1/assemblies/{id}/readiness
 * Exige erp.access e erp.assemblies.view, as mesmas da assembleia (fail-closed). Fora do escopo = 404; assembleia
 * encerrada ou cancelada = 422. Não grava nem envia nada.
 */
final class AssemblyReadiness
{
    /** @param array{id?:string} $data */
    public function show(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.assemblies.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            if ($id === false) {
                throw new RegistryNotFound();
            }
            $result = (new Service())->check($user, (int)$id);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Assembleia não encontrada no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'not_applicable', 'message' => $exception->getMessage()]]);
        }
    }
}
