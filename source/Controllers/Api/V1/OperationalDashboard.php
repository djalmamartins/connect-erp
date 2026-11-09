<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\OperationalDashboard as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Dashboard e continuações do Operacional (ADR 0053):
 *  GET  /api/v1/dashboard?condominium_id=          números e alertas no contexto (erp.access)
 *  POST /api/v1/reservations/{id}/call-waitlist    chama o primeiro da lista de espera (erp.reservations.manage)
 *  POST /api/v1/maintenances/{id}/next             gera a próxima manutenção (erp.maintenances.manage)
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class OperationalDashboard
{
    public function show(): void
    {
        // Painel operacional só para quem vê as unidades (ADR 0111): o parceiro (erp_partner) não vê contagens.
        $user = $this->authorize('erp.units.view');
        if ($user === null) {
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $this->run(200, fn () => (new Service())->summary($user, $condominium === false || $condominium === null ? null : (int)$condominium));
    }

    public function callWaitlist(array $data): void
    {
        $user = $this->authorize('erp.reservations.manage');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->callWaitlist($user, $this->id($data)));
        }
    }

    public function nextMaintenance(array $data): void
    {
        $user = $this->authorize('erp.maintenances.manage');
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->nextMaintenance($user, $this->id($data)));
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro ou condomínio não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(?string $permission): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || ($permission !== null && !Access::can($permission, $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
