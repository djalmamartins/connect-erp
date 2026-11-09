<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ResidentInvitations;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Support\Access;

/**
 * Acesso de imobiliárias e moradores (ADR 0090). Ver exige erp.access_admin.view; mudar, erp.access_admin.manage;
 * a consulta do parceiro, erp.partner.view. CSRF e sessão no middleware ApiAuthenticated.
 *  GET  /api/v1/partner-agencies                                   AP-148
 *  POST /api/v1/partner-agencies {name, document, email, phone}
 *  POST /api/v1/partner-agencies/{id}/deactivate
 *  POST /api/v1/partner-agencies/{id}/users {user_id}
 *  POST /api/v1/partner-users/{user}/unlink
 *  POST /api/v1/partner-users/{user}/condominiums {condominium_id}
 *  POST /api/v1/partner-users/{user}/condominiums/revoke {condominium_id}
 *  GET  /api/v1/partner/view?condominium_id=                       consulta do parceiro (erp.partner.view)
 *  GET  /api/v1/resident-invitations?condominium_id=               AP-152
 *  POST /api/v1/resident-invitations {condominium_id, resident_ids[]}
 *  POST /api/v1/resident-invitations/{id}/revoke
 *  GET  /api/v1/resident-portal-permissions?condominium_id=        AP-153
 *  POST /api/v1/resident-portal-permissions {condominium_id, feature, enabled}
 */
final class AccessAdmin
{
    public function agencies(): void
    {
        $user = $this->authorize(['erp.access_admin.view']);
        if ($user !== null) {
            $this->run(200, fn () => (new PartnerAccess())->listAgencies($user));
        }
    }

    public function storeAgency(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new PartnerAccess())->createAgency($user, $body));
        }
    }

    /** @param array{id?:string} $data */
    public function deactivateAgency(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new PartnerAccess())->deactivateAgency($user, $this->id($data, 'id')));
        }
    }

    /** @param array{id?:string} $data */
    public function linkUser(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new PartnerAccess())->linkUser($user, $this->id($data, 'id'), (int)$this->int($body['user_id'] ?? null)));
        }
    }

    /** @param array{user?:string} $data */
    public function unlinkUser(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new PartnerAccess())->unlinkUser($user, $this->id($data, 'user')));
        }
    }

    /** @param array{user?:string} $data */
    public function grant(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new PartnerAccess())->grant($user, $this->id($data, 'user'), $this->required($body['condominium_id'] ?? null)));
        }
    }

    /** @param array{user?:string} $data */
    public function revoke(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new PartnerAccess())->revoke($user, $this->id($data, 'user'), $this->required($body['condominium_id'] ?? null)));
        }
    }

    public function partnerView(): void
    {
        $user = $this->authorize(['erp.partner.view']);
        if ($user !== null) {
            $this->run(200, fn () => (new PartnerAccess())->view($user, $this->required($_GET['condominium_id'] ?? null)));
        }
    }

    public function invitations(): void
    {
        $user = $this->authorize(['erp.access_admin.view', 'erp.residents.view']);
        if ($user !== null) {
            $this->run(200, fn () => (new ResidentInvitations())->list($user, $this->required($_GET['condominium_id'] ?? null)));
        }
    }

    public function invite(): void
    {
        [$user, $body] = $this->write('erp.residents.view');
        if ($user !== null) {
            $this->run(201, fn () => (new ResidentInvitations())->invite($user, $this->required($body['condominium_id'] ?? null),
                is_array($body['resident_ids'] ?? null) ? $body['resident_ids'] : []));
        }
    }

    /** @param array{id?:string} $data */
    public function revokeInvitation(array $data): void
    {
        [$user] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new ResidentInvitations())->revoke($user, $this->id($data, 'id')));
        }
    }

    public function portal(): void
    {
        $user = $this->authorize(['erp.access_admin.view']);
        if ($user !== null) {
            $this->run(200, fn () => (new ResidentPortalPermissions())->matrix($user, $this->required($_GET['condominium_id'] ?? null)));
        }
    }

    public function setPortal(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new ResidentPortalPermissions())->set($user, $this->required($body['condominium_id'] ?? null), $body['feature'] ?? null, $body['enabled'] ?? null));
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'access_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(?string $extra = null): array
    {
        $user = $this->authorize(array_values(array_filter(['erp.access_admin.view', 'erp.access_admin.manage', $extra])));
        if ($user === null) {
            return [null, []];
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return [null, []];
        }
        return [$user, $body];
    }

    /** @param list<string> $permissions todas exigidas, além de erp.access */
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

    private function required(mixed $value): int
    {
        return $this->int($value) ?? throw new InvalidArgumentException('Escolha o condomínio.');
    }

    private function id(array $data, string $key): int
    {
        return $this->int($data[$key] ?? null) ?? 0;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
