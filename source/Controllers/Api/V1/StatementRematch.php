<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\StatementRematch as Service;
use Source\Support\Access;

/**
 * Nova conferência do extrato pendente (ADR 0209):
 *  POST /api/v1/bank-statement-lines/rematch {condominium_id, bank_account_id?, dry_run?}
 * Exige as mesmas permissões da conciliação à mão: erp.access, erp.imports.view, erp.imports.manage e erp.entries.manage
 * (fail-closed). CSRF pelo ApiAuthenticated. Condomínio fora do escopo ou conta de outro condomínio = 404; entrada
 * inválida = 422.
 */
final class StatementRematch
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.imports.view', $user) || !Access::can('erp.imports.manage', $user)
            || !Access::can('erp.entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->rematch($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio ou conta bancária não encontrada no seu escopo.']]);
        }
    }
}
