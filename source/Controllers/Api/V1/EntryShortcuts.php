<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\EntryShortcuts as Service;
use Source\Services\Erp\Registry\RegistryLocked;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Lançamentos avulsos (ADR 0057):
 *  POST /api/v1/entries/{id}/discount {amount_cents, reason}      desconto (erp.entries.manage)
 *  GET  /api/v1/entries/{id}/adjustments                         trilha dos descontos (erp.entries.view)
 *  GET  /api/v1/extra-charges?condominium_id=&kind=&status=       multas avulsas e cobranças extras (erp.invoices.view)
 *  POST /api/v1/extra-charges {condominium_id, kind, description, chart_account_id, competence, due_on, method, amount_cents, unit_ids, related_entry_id, reason}
 *  GET  /api/v1/extra-charges/{id}                               com as contas geradas
 *  POST /api/v1/extra-charges/{id}/cancel {reason}               (erp.invoices.manage)
 *  GET  /api/v1/bank-transfers?condominium_id=&status=            transferências entre contas (erp.entries.view)
 *  POST /api/v1/bank-transfers {condominium_id, from_bank_account_id, to_bank_account_id, amount_cents, transferred_on, description}
 *  GET  /api/v1/bank-transfers/{id}
 *  POST /api/v1/bank-transfers/{id}/cancel {reason}              (erp.entries.manage)
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class EntryShortcuts
{
    /** Permissões [ver, gravar] de cada grupo de rotas. */
    private const ENTRIES = ['erp.entries.view', 'erp.entries.manage'];
    private const INVOICES = ['erp.invoices.view', 'erp.invoices.manage'];

    public function discount(array $data): void
    {
        [$user, $body] = $this->write(self::ENTRIES);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->discount($user, (int)$this->int($data['id'] ?? null), $body['amount_cents'] ?? null, $body['reason'] ?? null));
        }
    }

    public function adjustments(array $data): void
    {
        $user = $this->authorize(self::ENTRIES, false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->adjustments($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function charges(): void
    {
        $user = $this->authorize(self::INVOICES, false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->listCharges($user, $this->int($_GET['condominium_id'] ?? null),
                ['kind' => $_GET['kind'] ?? null, 'status' => $_GET['status'] ?? null]), false);
        }
    }

    public function charge(array $data): void
    {
        $user = $this->authorize(self::INVOICES, false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->findCharge($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function storeCharge(): void
    {
        [$user, $body] = $this->write(self::INVOICES);
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->createCharge($user, $body));
        }
    }

    public function cancelCharge(array $data): void
    {
        [$user, $body] = $this->write(self::INVOICES);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancelCharge($user, (int)$this->int($data['id'] ?? null), $body['reason'] ?? null));
        }
    }

    public function transfers(): void
    {
        $user = $this->authorize(self::ENTRIES, false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->listTransfers($user, $this->int($_GET['condominium_id'] ?? null), ['status' => $_GET['status'] ?? null]), false);
        }
    }

    public function transfer(array $data): void
    {
        $user = $this->authorize(self::ENTRIES, false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->findTransfer($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function storeTransfer(): void
    {
        [$user, $body] = $this->write(self::ENTRIES);
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->transfer($user, $body));
        }
    }

    public function cancelTransfer(array $data): void
    {
        [$user, $body] = $this->write(self::ENTRIES);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancelTransfer($user, (int)$this->int($data['id'] ?? null), $body['reason'] ?? null));
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro ou condomínio não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Um lançamento não passou nas regras.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryLocked | DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'entry_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    /** @param array{0:string, 1:string} $permission */
    private function write(array $permission): array
    {
        $user = $this->authorize($permission, true);
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

    /** @param array{0:string, 1:string} $permission */
    private function authorize(array $permission, bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can($permission[0], $user) || ($manage && !Access::can($permission[1], $user))) {
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
