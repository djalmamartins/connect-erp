<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Collections as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Cobrança e multas (ADR 0080):
 *  GET  /api/v1/pending-fines?condominium_id=&include_paid=1        multas avulsas pendentes (AP-116)
 *  GET  /api/v1/collection-desk?condominium_id=&as_of=              fila de atendimento (AP-117)
 *  GET  /api/v1/units/{id}/collection-contacts                      contatos da unidade
 *  POST /api/v1/collection-contacts {unit_id, channel, outcome, note, contact_name, promise_on, promise_cents, next_action_on}
 *  GET  /api/v1/legal-cases?condominium_id=&status=&include_closed=1 casos judiciais (AP-039)
 *  POST /api/v1/legal-cases {unit_id, entry_ids, lawyer_id, case_number, court, opened_on, note}
 *  GET  /api/v1/legal-cases/{id}                                    com as contas e o andamento
 *  POST /api/v1/legal-cases/{id}/events {note, to_status, happened_on, lawyer_id, case_number, court}
 * Ver exige erp.collections.view; gravar, erp.collections.manage. A fila e o caso exigem também
 * erp.agreements.view (dados da inadimplência). CSRF e sessão no middleware ApiAuthenticated.
 */
final class Collections
{
    public function fines(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->pendingFines($user, $this->int($_GET['condominium_id'] ?? null),
                filter_var($_GET['include_paid'] ?? false, FILTER_VALIDATE_BOOL)), false);
        }
    }

    public function desk(): void
    {
        $user = $this->authorize(false, 'erp.agreements.view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->desk($user, $this->int($_GET['condominium_id'] ?? null),
                is_string($_GET['as_of'] ?? null) && $_GET['as_of'] !== '' ? $_GET['as_of'] : null), false);
        }
    }

    public function contacts(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->contacts($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function storeContact(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->recordContact($user, $body));
        }
    }

    public function cases(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->listCases($user, $this->int($_GET['condominium_id'] ?? null),
                ['status' => $_GET['status'] ?? null, 'include_closed' => $_GET['include_closed'] ?? null]), false);
        }
    }

    public function storeCase(): void
    {
        [$user, $body] = $this->write('erp.agreements.view');
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->openCase($user, $body));
        }
    }

    public function showCase(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->findCase($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function caseEvent(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->addEvent($user, (int)$this->int($data['id'] ?? null), $body));
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro ou condomínio não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'collection_conflict', 'message' => $exception->getMessage()]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.collections.view', $user) || ($manage && !Access::can('erp.collections.manage', $user))
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
