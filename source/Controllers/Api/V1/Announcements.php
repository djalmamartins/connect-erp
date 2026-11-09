<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Announcements as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Comunicação pela outbox (ADR 0059):
 *  GET  /api/v1/announcements?condominium_id=&kind=&scheduled=1      comunicados e avisos (AP-111, AP-137, AP-138)
 *  POST /api/v1/announcements {condominium_id, subject, body, audience, unit_ids, send_at}   comunicado geral
 *  POST /api/v1/announcements/collection {condominium_id, as_of, min_days_late, unit_ids, send_at}   aviso de cobrança
 *  GET  /api/v1/announcements/{id}                                  com a situação da entrega
 *  POST /api/v1/announcements/{id}/cancel {reason}                  cancela o que ainda não saiu
 *  GET  /api/v1/communication-addresses?condominium_id=             endereços de comunicação (AP-139)
 * Ver exige erp.announcements.view; gravar, erp.announcements.manage. O aviso de cobrança exige também
 * erp.agreements.view, e os endereços, erp.residents.view. CSRF e sessão no middleware ApiAuthenticated.
 */
final class Announcements
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null),
                ['kind' => $_GET['kind'] ?? null, 'scheduled' => $_GET['scheduled'] ?? null]), false);
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->announce($user, $body));
        }
    }

    public function collection(): void
    {
        [$user, $body] = $this->write('erp.agreements.view');
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->collection($user, $body));
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function cancel(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancel($user, (int)$this->int($data['id'] ?? null), $body['reason'] ?? null));
        }
    }

    public function addresses(): void
    {
        $user = $this->authorize(false, 'erp.residents.view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->addresses($user, $this->int($_GET['condominium_id'] ?? null)), false);
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Comunicado ou condomínio não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'announcement_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(?string $extra = null): array
    {
        $user = $this->authorize(true, $extra);
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

    private function authorize(bool $manage, ?string $extra = null): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.announcements.view', $user) || ($manage && !Access::can('erp.announcements.manage', $user))
            || ($extra !== null && !Access::can($extra, $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
