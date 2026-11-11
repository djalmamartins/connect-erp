<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankRemittances;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Boletos e retorno bancário (ADR 0035 e 0156), pelo adaptador da conta (sandbox ou Inter sandbox):
 *  GET  /api/v1/bank-charges?condominium_id=&status=&page=       lista (erp.bank_charges.view)
 *  GET  /api/v1/bank-charges/{id}
 *  POST /api/v1/bank-charges {entry_id, bank_account_id}          emite (idempotente por lançamento)
 *  POST /api/v1/bank-charges/{id}/refresh                         consulta no banco
 *  POST /api/v1/bank-charges/{id}/cancel                          cancela
 *  GET  /api/v1/bank-charges/{id}/pdf                             PDF do boleto (Inter, ADR 0156)
 *  GET  /api/v1/bank-remittances?condominium_id=&from=&to=&status=&page=  remessas: lotes por conta e dia (AP-037)
 *  GET  /api/v1/bank-returns?condominium_id=&bank_account_id=&page=  arquivos importados (paginado)
 *  POST /api/v1/bank-returns {bank_account_id, filename, content} importa retorno (texto)
 *  GET  /api/v1/bank-events/divergences?condominium_id=           fila de revisão
 *  POST /api/v1/bank-events/{id}/resolve {note}                   marca como revisada
 * Gravar exige erp.bank_charges.manage. CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class Banking
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new BankCharges())->list($user, $this->int($_GET['condominium_id'] ?? null), ['status' => $_GET['status'] ?? null, 'page' => $_GET['page'] ?? 1]), false);
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new BankCharges())->find($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new BankCharges())->issue($user, (int)$this->int($body['entry_id'] ?? null), (int)$this->int($body['bank_account_id'] ?? null)));
        }
    }

    public function refresh(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $this->run(200, fn () => (new BankCharges())->refresh($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function cancel(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $this->run(200, fn () => (new BankCharges())->cancel($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    /** PDF do boleto direto do banco (só adaptadores que oferecem, ex.: Inter). */
    public function pdf(array $data): void
    {
        $user = $this->authorize('view');
        if ($user === null) {
            return;
        }
        $id = (int)$this->int($data['id'] ?? null);
        try {
            $pdf = (new BankCharges())->pdf($user, $id);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro não encontrado no seu escopo.']]);
            return;
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'banking_conflict', 'message' => $exception->getMessage()]]);
            return;
        } catch (\RuntimeException $exception) {
            \Source\Support\AppLogger::exception($exception, 'erp', ['event_type' => 'bank_charge_pdf_failed', 'charge_id' => $id]);
            JsonResponse::send(502, ['error' => ['code' => 'bank_unavailable', 'message' => 'O banco não devolveu o PDF agora. Tente de novo.']]);
            return;
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="boleto-' . $id . '.pdf"');
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $pdf;
    }

    public function importReturn(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new BankReturns())->process($user, (int)$this->int($body['bank_account_id'] ?? null),
                is_string($body['filename'] ?? null) ? $body['filename'] : 'retorno.txt', is_string($body['content'] ?? null) ? $body['content'] : ''));
        }
    }

    public function remittances(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new BankRemittances())->list($user, $this->int($_GET['condominium_id'] ?? null), [
                'from' => $_GET['from'] ?? null, 'to' => $_GET['to'] ?? null, 'status' => $_GET['status'] ?? null, 'page' => $_GET['page'] ?? 1,
            ]), false);
        }
    }

    public function returns(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new BankReturns())->files($user, $this->int($_GET['condominium_id'] ?? null), [
                'bank_account_id' => $_GET['bank_account_id'] ?? null, 'page' => $_GET['page'] ?? 1,
            ]), false);
        }
    }

    public function divergences(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new BankReturns())->divergences($user, $this->int($_GET['condominium_id'] ?? null)));
        }
    }

    public function resolve(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new BankReturns())->resolve($user, (int)$this->int($data['id'] ?? null), is_string($body['note'] ?? null) ? $body['note'] : ''));
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'banking_conflict', 'message' => $exception->getMessage()]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.bank_charges.view', $user) || ($action === 'manage' && !Access::can('erp.bank_charges.manage', $user))) {
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
