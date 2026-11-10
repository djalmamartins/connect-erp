<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AccountingReports;
use Source\Services\Erp\Investments as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Aplicações financeiras (ADR 0170). CSRF e sessão ficam no middleware ApiAuthenticated:
 *  GET  /api/v1/investments/positions?condominium_id=&as_of=           saldos separados (erp.entries.view + erp.chart.view)
 *  GET  /api/v1/reports/bank-ledger?condominium_id=&from=&to=          Razão das contas bancárias (erp.entries.view + erp.chart.view)
 *  POST /api/v1/investments/{id}/link {parent_bank_account_id|null}    liga a aplicação à conta corrente (erp.bank_accounts.manage)
 *  POST /api/v1/investments/{id}/apply {amount_cents, transferred_on, description?}    aplicar (erp.entries.manage)
 *  POST /api/v1/investments/{id}/redeem {amount_cents, transferred_on, description?}   resgatar (erp.entries.manage)
 * O estorno é o cancelamento da transferência: POST /api/v1/bank-transfers/{id}/cancel (AP-029). Fora do
 * escopo = 404; inválido = 422; competência fechada = 409.
 */
final class Investments
{
    public function positions(): void
    {
        $user = $this->authorize(['erp.entries.view', 'erp.chart.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->positions($user, $this->int($_GET['condominium_id'] ?? null), $_GET['as_of'] ?? null));
        }
    }

    public function bankLedger(): void
    {
        $user = $this->authorize(['erp.entries.view', 'erp.chart.view']);
        if ($user !== null) {
            $this->run(fn (): array => (new AccountingReports())->bankLedger($user, $this->int($_GET['condominium_id'] ?? null), $_GET['from'] ?? null, $_GET['to'] ?? null));
        }
    }

    /** @param array{id?:string} $data */
    public function link(array $data): void
    {
        [$user, $body] = $this->write(['erp.bank_accounts.view', 'erp.bank_accounts.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->link($user, $this->int($data['id'] ?? null) ?? 0, $body['parent_bank_account_id'] ?? null));
        }
    }

    /** @param array{id?:string} $data */
    public function apply(array $data): void
    {
        [$user, $body] = $this->write(['erp.entries.view', 'erp.entries.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->apply($user, $this->int($data['id'] ?? null) ?? 0, $body), 201);
        }
    }

    /** @param array{id?:string} $data */
    public function redeem(array $data): void
    {
        [$user, $body] = $this->write(['erp.entries.view', 'erp.entries.manage']);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->redeem($user, $this->int($data['id'] ?? null) ?? 0, $body), 201);
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
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Conta ou condomínio não encontrado no seu escopo.']]);
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

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
