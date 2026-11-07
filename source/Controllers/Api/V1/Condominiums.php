<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Access;

/** Leitura de condomínios sempre limitada ao escopo do usuário (ADR-002). */
final class Condominiums
{
    public function index(): void
    {
        $user = Auth::user();
        if (!Access::can('erp.condominiums.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        JsonResponse::send(200, ['data' => (new CondominiumScope())->allowed($user)]);
    }

    public function show(array $data): void
    {
        $user = Auth::user();
        if (!Access::can('erp.condominiums.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $scope = new CondominiumScope();
        try {
            $scope->assertAccess($user, (int)$id);
        } catch (CondominiumAccessDenied) {
            // 404 para não revelar a existência de condomínios fora do escopo.
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado.']]);
            return;
        }
        foreach ($scope->allowed($user) as $condominium) {
            if ($condominium['id'] === (int)$id) {
                JsonResponse::send(200, ['data' => $condominium]);
                return;
            }
        }
        JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado.']]);
    }
}
