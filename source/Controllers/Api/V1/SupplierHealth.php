<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Erp\SupplierHealth as Service;
use Source\Support\Access;

/**
 * Conferência do cadastro de fornecedores (ADR 0200), somente leitura:
 *  GET /api/v1/reports/supplier-health
 * Exige erp.access, erp.suppliers.view e erp.entries.view (as contas a pagar entram nas conferências); sem uma delas, 403.
 * As contas a pagar são só dos condomínios do escopo do usuário; o "sem uso" só para quem acessa todos.
 */
final class SupplierHealth
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.suppliers.view', $user) || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $data = (new Service())->build($user);
        header('Cache-Control: no-store, private');
        JsonResponse::send(200, ['data' => $data]);
    }
}
