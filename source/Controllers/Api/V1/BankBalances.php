<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Erp\BankBalances as BankBalancesService;
use Source\Support\Access;

/**
 * GET /api/v1/bank-accounts/balances (ADR 0024): saldo calculado de cada conta bancária do
 * escopo do usuário. Mesma permissão de leitura das contas bancárias. Somente leitura.
 */
final class BankBalances
{
    public function index(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.bank_accounts.view', $user) || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        header('Cache-Control: no-store, private');
        JsonResponse::send(200, ['data' => (new BankBalancesService())->forUser($user, $condominium === false || $condominium === null ? null : $condominium)]);
    }
}
