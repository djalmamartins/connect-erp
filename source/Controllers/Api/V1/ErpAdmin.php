<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AdminReports;
use Source\Services\Erp\ErpAccess;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Support\Access;

/**
 * Administração, relatórios enviados, usuários e permissões do ERP (ADR 0168). CSRF e sessão ficam no
 * middleware ApiAuthenticated:
 *  GET  /api/v1/erp-admin/overview                                     AP-091 (erp.settings.view)
 *  GET  /api/v1/reports/sent?from=&to=&report=                         AP-100 (erp.audit.view)
 *  GET  /api/v1/erp-users?condominium_id=                              AP-149 (erp.users.view)
 *  POST /api/v1/erp-users/invite {email, condominium_ids[]}            AP-149 (erp.users.manage)
 *  POST /api/v1/erp-users/{id}/deactivate {condominium_id?}            AP-149 (erp.users.manage)
 *  GET  /api/v1/erp-permissions                                        AP-150 (erp.permissions.view)
 *  POST /api/v1/erp-permissions {role, permission, allowed}            AP-150 (erp.permissions.manage)
 *  GET  /api/v1/erp-users/{id}/permissions                             AP-150 (erp.permissions.view)
 *  POST /api/v1/erp-users/{id}/permissions {permission, effect}        AP-150 (erp.permissions.manage)
 * Fora do escopo = 404; inválido = 422; sem poder sobre o papel ou a permissão = 403; nada a desativar = 409.
 */
final class ErpAdmin
{
    public function overview(): void
    {
        $user = $this->authorize(['erp.settings.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new AdminReports())->overview($user));
        }
    }

    public function sentReports(): void
    {
        $user = $this->authorize(['erp.audit.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new AdminReports())->sent($user, $_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['report'] ?? null));
        }
    }

    public function users(): void
    {
        $user = $this->authorize(['erp.users.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->users($user, $this->int($_GET['condominium_id'] ?? null)));
        }
    }

    public function invite(): void
    {
        [$user, $body] = $this->write(['erp.users.view', 'erp.users.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->invite($user, $body['email'] ?? null, is_array($body['condominium_ids'] ?? null) ? $body['condominium_ids'] : []));
        }
    }

    /** @param array{id?:string} $data */
    public function deactivate(array $data): void
    {
        [$user, $body] = $this->write(['erp.users.view', 'erp.users.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->deactivate($user, $this->id($data), $this->int($body['condominium_id'] ?? null)));
        }
    }

    public function permissions(): void
    {
        $user = $this->authorize(['erp.permissions.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->matrix($user));
        }
    }

    public function setRolePermission(): void
    {
        [$user, $body] = $this->write(['erp.permissions.view', 'erp.permissions.manage']);
        if ($user !== null) {
            $allowed = filter_var($body['allowed'] ?? null, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($allowed === null) {
                JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe allowed (true ou false).']]);
                return;
            }
            $this->run(fn (): array => (new ErpAccess())->setRolePermission($user, $body['role'] ?? null, $body['permission'] ?? null, $allowed));
        }
    }

    /** @param array{id?:string} $data */
    public function userPermissions(array $data): void
    {
        $user = $this->authorize(['erp.permissions.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->userPermissions($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function setUserPermission(array $data): void
    {
        [$user, $body] = $this->write(['erp.permissions.view', 'erp.permissions.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new ErpAccess())->setUserPermission($user, $this->id($data), $body['permission'] ?? null, $body['effect'] ?? null));
        }
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
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Usuário ou condomínio não encontrado no seu escopo.']]);
        } catch (RegistryForbidden) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Você não pode alterar este papel, usuário ou permissão.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(array $permissions): array
    {
        $user = $this->authorize($permissions);
        if ($user === null) {
            return [null, []];
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return [null, []];
        }
        return [$user, $body];
    }

    /** @param list<string> $permissions */
    private function authorize(array $permissions): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        foreach (array_merge(['erp.access'], $permissions) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return null;
            }
        }
        return $user;
    }

    /** @param array{id?:string} $data */
    private function id(array $data): int
    {
        return $this->int($data['id'] ?? null) ?? 0;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
