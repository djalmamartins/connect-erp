<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\ReservationFeeBilling as Service;
use Source\Support\Access;

/**
 * Cobrança das taxas de reserva em lote (ADR 0212):
 *  POST /api/v1/reservations/bill-fees {condominium_id, from, to, due_on, chart_account_id, dry_run?}
 * Exige erp.access, erp.reservations.view e as permissões da cobrança extra (erp.invoices.view e erp.invoices.manage),
 * fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada inválida ou reserva recusada = 422 (nada é gravado).
 */
final class ReservationFeeBilling
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        foreach (['erp.access', 'erp.reservations.view', 'erp.invoices.view', 'erp.invoices.manage'] as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->bill($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Reserva recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
