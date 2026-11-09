<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Services\Erp\RecurringEntries;
use Source\Support\Access;

/**
 * POST /api/v1/recurring-entries/generate (ADR 0027): gera os lançamentos de uma competência a
 * partir dos lançamentos fixos. Corpo JSON {"competence": "AAAA-MM", "condominium_id": opcional}.
 * CSRF e sessão no middleware ApiAuthenticated. Repetir a chamada não duplica lançamentos.
 */
final class RecurringEntriesGenerate
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.recurring_entries.manage', $user) || !Access::can('erp.entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $competence = is_string($body['competence'] ?? null) ? trim($body['competence']) : '';
        if (!RecurringEntries::isCompetence($competence)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Dados inválidos.', 'fields' => ['competence' => 'Use ano e mês, ex.: 2026-11.']]]);
            return;
        }
        $condominium = filter_var($body['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $result = (new RecurringEntries())->generate($user, $competence, $condominium === false || $condominium === null ? null : $condominium);
        header('Cache-Control: no-store');
        JsonResponse::send(200, ['data' => $result]);
    }
}
