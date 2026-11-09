<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Agreements as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Inadimplência e acordos (AP-033/034, ADR 0039):
 *  GET  /api/v1/delinquency?condominium_id=&as_of=AAAA-MM-DD   contas vencidas por unidade (erp.agreements.view)
 *  GET  /api/v1/agreements?condominium_id=&status=&page=       lista
 *  GET  /api/v1/agreements/{id}                                acordo com contas originais e parcelas
 *  POST /api/v1/agreements {unit_id, entry_ids[], installments, first_due_on, chart_account_id, fees_cents, discount_cents, notes}
 *  POST /api/v1/agreements/{id}/cancel {reason}
 *  GET  /api/v1/agreements/suggestion?unit_id=&entry_ids=1,2&as_of=   encargos sugeridos (ADR 0040)
 *  POST /api/v1/agreements/{id}/breach {reason}               grava a quebra (só se a regra indicar)
 * Gravar exige erp.agreements.manage. CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class Agreements
{
    public function delinquency(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->delinquency($user, $this->int($_GET['condominium_id'] ?? null), is_string($_GET['as_of'] ?? null) ? $_GET['as_of'] : null));
        }
    }

    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), ['status' => $_GET['status'] ?? null, 'page' => $_GET['page'] ?? 1]), false);
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->create($user, $body));
        }
    }

    public function cancel(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancel($user, (int)$this->int($data['id'] ?? null), is_string($body['reason'] ?? null) ? $body['reason'] : ''));
        }
    }

    public function suggestion(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $ids = is_string($_GET['entry_ids'] ?? null) ? explode(',', $_GET['entry_ids']) : (is_array($_GET['entry_ids'] ?? null) ? $_GET['entry_ids'] : []);
            $this->run(200, fn () => (new Service())->suggestion($user, (int)$this->int($_GET['unit_id'] ?? null), $ids,
                is_string($_GET['as_of'] ?? null) ? $this->date($_GET['as_of']) : null));
        }
    }

    public function breach(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->breach($user, (int)$this->int($data['id'] ?? null), is_string($body['reason'] ?? null) ? $body['reason'] : ''));
        }
    }

    private function date(string $value): string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Data de referência inválida (AAAA-MM-DD).');
        }
        return $value;
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Uma parcela não passou nas regras dos lançamentos.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'agreement_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(): array
    {
        $user = $this->authorize('manage');
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

    private function authorize(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.agreements.view', $user) || ($action === 'manage' && !Access::can('erp.agreements.manage', $user))) {
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
