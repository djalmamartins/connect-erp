<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Erp\ExpenseBenchmark as Service;
use Source\Support\Access;

/**
 * Comparativo de despesas entre condomínios (ADR 0201), somente leitura:
 *  GET /api/v1/reports/expense-benchmark?from=AAAA-MM&to=AAAA-MM&tolerance=10..500
 * Exige erp.access e erp.entries.view. Só os condomínios do escopo do usuário; entrada inválida = 422.
 */
final class ExpenseBenchmark
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $data = (new Service())->build($user, $_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['tolerance'] ?? null);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        }
    }
}
