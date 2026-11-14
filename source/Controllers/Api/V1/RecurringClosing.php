<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\RecurringClosing as Service;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Encerramento em lote dos lançamentos fixos que já passaram da última competência (ADR 0271):
 *  POST /api/v1/recurring-entries/close-ended {condominium_id, recurring_ids?, before_competence?, reason, dry_run?}
 * Exige erp.access, erp.recurring_entries.view e erp.recurring_entries.manage, as mesmas do cadastro; fail-closed. CSRF
 * pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida ou lançamento fixo recusado = 422 (nada é gravado). Nenhum
 * aviso é enviado.
 */
final class RecurringClosing
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.recurring_entries.view', $user) || !Access::can('erp.recurring_entries.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->close($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Encerramento recusado: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
