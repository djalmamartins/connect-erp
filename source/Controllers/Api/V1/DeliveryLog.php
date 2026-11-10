<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\DeliveryLog as Service;
use Source\Support\Access;

/**
 * Registros de Entregas (AP-136, ADR 0166):
 *  GET  /api/v1/reports/deliveries?condominium_id=&status=&from=&to=&unit_id=   histórico com resumo
 *  POST /api/v1/delivery-pickups {delivery_id, picked_up_by, picked_up_on?}     baixa da retirada
 * A chegada é registrada pelo cadastro de Entregas (AP-135, motor de cadastros). Ver exige
 * erp.deliveries.view; a baixa, erp.deliveries.manage. CSRF e sessão no middleware ApiAuthenticated;
 * a baixa é auditada no service. Fora do escopo = 404; inválido = 422; já retirada ou cancelada = 409.
 */
final class DeliveryLog
{
    public function index(): void
    {
        $user = $this->authorize('erp.deliveries.view');
        if ($user === null) {
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->run(fn (): array => (new Service())->log($user, $condominium === false || $condominium === null ? null : (int)$condominium, $_GET));
    }

    public function pickUp(): void
    {
        $user = $this->authorize('erp.deliveries.manage');
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $id = filter_var($body['delivery_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Escolha a entrega.']]);
            return;
        }
        $this->run(fn (): array => (new Service())->pickUp($user, (int)$id, $body['picked_up_by'] ?? null, $body['picked_up_on'] ?? null));
    }

    private function run(callable $action): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Entrega ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'delivery_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.deliveries.view', $user) || !Access::can($permission, $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }
}
