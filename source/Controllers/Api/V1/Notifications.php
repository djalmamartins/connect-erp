<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Fila de avisos do ERP (ADR 0055):
 *  GET  /api/v1/notifications?condominium_id=&status=   avisos da outbox (erp.notifications.view)
 *  GET  /api/v1/notifications/{id}                      aviso com a trilha de entrega
 *  POST /api/v1/notifications/{id}/retry                reenvia aviso com falha ou cancelado (erp.notifications.manage)
 *  POST /api/v1/notifications/dispatch                  processa a fila agora (erp.notifications.manage)
 * O modo de entrega (log, sandbox, production) vem do ambiente; fora de produção nada sai para morador.
 */
final class Notifications
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $this->run(fn () => (new NotificationDispatcher())->list($user, $condominium === false || $condominium === null ? null : (int)$condominium,
                ['status' => $_GET['status'] ?? null]), false);
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(fn () => (new NotificationDispatcher())->find($user, $this->id($data)));
        }
    }

    public function retry(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $this->run(fn () => (new NotificationDispatcher())->retry($user, $this->id($data)));
        }
    }

    public function dispatch(): void
    {
        $user = $this->authorize('manage');
        if ($user === null) {
            return;
        }
        // A fila é de todos os condomínios: disparar à mão exige acesso a todos (ADR 0061).
        if (!(new CondominiumScope())->hasGlobalAccess($user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Disparar a fila de avisos exige acesso a todos os condomínios.']]);
            return;
        }
        $this->run(fn () => (new NotificationDispatcher())->dispatch(50));
    }

    private function run(callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Aviso não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.notifications.view', $user) || ($action === 'manage' && !Access::can('erp.notifications.manage', $user))) {
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
