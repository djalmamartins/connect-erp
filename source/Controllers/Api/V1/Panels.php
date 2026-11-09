<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Erp\PanelSummaries;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Painéis (ADR 0054), somente leitura:
 *  GET /api/v1/panels/condominium/{id}   painel do condomínio (AP-002)
 *  GET /api/v1/panels/unit/{id}          painel da unidade (AP-003)
 * Exigem erp.access e erp.units.view. Fora do escopo = 404.
 */
final class Panels
{
    public function condominium(array $data): void
    {
        $this->run(fn ($user) => (new PanelSummaries())->condominium($user, $this->id($data)));
    }

    public function unit(array $data): void
    {
        $this->run(fn ($user) => (new PanelSummaries())->unit($user, $this->id($data)));
    }

    private function run(callable $action): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.units.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = $action($user);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado no seu escopo.']]);
        }
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
