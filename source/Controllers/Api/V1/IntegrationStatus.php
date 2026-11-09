<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Erp\IntegrationStatus as Service;
use Source\Support\Access;

/**
 * Integrações (AP-171, ADR 0091), só leitura:
 *  GET /api/v1/integrations   estado do banco, do e-mail, dos índices e da fila (erp.settings.view)
 */
final class IntegrationStatus
{
    public function index(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.settings.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        header('Cache-Control: no-store, private');
        JsonResponse::send(200, ['data' => (new Service())->overview($user)]);
    }
}
