<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AccountabilityLinks as Service;
use Source\Support\Access;

/**
 * Link público da prestação de contas (ADR 0300). CSRF e sessão ficam no middleware ApiAuthenticated:
 *  GET  /api/v1/accountability-links?condominium_id=&kind=&period=           links do condomínio (sem o conteúdo)
 *  POST /api/v1/accountability-links {condominium_id, kind?, period}          gera ou atualiza (mês fechado; anual: o ano todo)
 *  POST /api/v1/accountability-links/{id}/send                                envia aos moradores (uma vez por versão)
 *  POST /api/v1/accountability-links/{id}/revoke {reason}                     desliga o endereço
 * Ver exige erp.closing.view e erp.entries.view; gerar, enviar e revogar, também erp.closing.manage.
 * Fora do escopo = 404; inválido = 422; mês aberto ou link revogado = 409.
 */
final class AccountabilityLinks
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), $_GET['kind'] ?? null, $_GET['period'] ?? null));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->generate($user, $this->int($body['condominium_id'] ?? null), $body['kind'] ?? 'monthly', $body['period'] ?? null), 201);
        }
    }

    /** @param array{id?:string} $data */
    public function send(array $data): void
    {
        [$user] = $this->write(false);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->notifyResidents($user, $this->int($data['id'] ?? null) ?? 0));
        }
    }

    /** @param array{id?:string} $data */
    public function revoke(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->revoke($user, $this->int($data['id'] ?? null) ?? 0, $body['reason'] ?? null));
        }
    }

    private function run(callable $action, int $status = 200): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status === 201 && ($data['created'] ?? true) === false ? 200 : $status, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Link ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(bool $needsBody = true): array
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return [null, []];
        }
        $raw = (string)file_get_contents('php://input');
        $body = $raw === '' && !$needsBody ? [] : json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return [null, []];
        }
        return [$user, $body];
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || !Access::can('erp.entries.view', $user)
            || ($manage && !Access::can('erp.closing.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function int(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $id === null ? null : (int)$id;
    }
}
