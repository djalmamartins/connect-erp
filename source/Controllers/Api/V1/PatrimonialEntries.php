<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\PatrimonialEntries as Service;
use Source\Support\Access;

/**
 * Lançamentos patrimoniais com partida dobrada (ADR 0169). CSRF e sessão ficam no middleware ApiAuthenticated:
 *  GET  /api/v1/patrimonial-entries?condominium_id=&from=&to=
 *  POST /api/v1/patrimonial-entries {condominium_id, entry_date, description, amount_cents, debit_account_id, credit_account_id,
 *                                    bank_account_id?, linked_entry_id?}
 *  POST /api/v1/patrimonial-entries/{id}/reverse {reason, date?}      estorno (não há exclusão)
 * Ver exige erp.entries.view e erp.chart.view; lançar e estornar, também erp.entries.manage. Fora do escopo =
 * 404; inválido = 422; competência fechada ou lançamento já estornado = 409.
 */
final class PatrimonialEntries
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $this->run(fn (): array => (new Service())->list($user, $condominium === false || $condominium === null ? null : (int)$condominium,
                $_GET['from'] ?? null, $_GET['to'] ?? null));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->create($user, $body), 201);
        }
    }

    /** @param array{id?:string} $data */
    public function reverse(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $this->run(fn (): array => (new Service())->reverse($user, $id === false || $id === null ? 0 : (int)$id, $body['reason'] ?? null, $body['date'] ?? null));
        }
    }

    private function run(callable $action, int $status = 200): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Lançamento ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(): array
    {
        $user = $this->authorize(true);
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

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)
            || ($manage && !Access::can('erp.entries.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }
}
