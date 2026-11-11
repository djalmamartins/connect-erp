<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\InstallmentPayables as Service;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Despesa parcelada (ADR 0195):
 *  POST /api/v1/entries/installments {condominium_id, chart_account_id, description, total, installments, first_due_on,
 *       competence?, cost_center_id?, supplier_id?, document_number?, bank_account_id?, notes?, dry_run?}
 * Exige erp.access, erp.entries.view e erp.entries.manage (as mesmas de lançar conta a pagar); CSRF pelo ApiAuthenticated.
 * Fora do escopo = 404; entrada inválida = 422 (com errors por campo quando a parcela é recusada; nada é gravado).
 */
final class InstallmentPayables
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->create($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send($data['dry_run'] ? 200 : 201, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Parcela recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
