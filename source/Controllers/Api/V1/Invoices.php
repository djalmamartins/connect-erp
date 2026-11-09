<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Invoices as InvoiceService;
use Source\Services\Erp\Registry\RegistryLocked;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Faturas (AP-032, ADR 0033):
 *  GET   /api/v1/invoices?condominium_id=&competence=&status=&page=   lista (erp.invoices.view)
 *  GET   /api/v1/invoices/{id}                                        fatura com itens
 *  POST  /api/v1/invoices  {condominium_id, invoice_type_id, competence, due_on, method, amount_cents, notes}
 *  PATCH /api/v1/invoices/{id}/items/{item}  {amount_cents}           ajusta item (rascunho)
 *  POST  /api/v1/invoices/{id}/issue                                  emite (contas a receber)
 *  POST  /api/v1/invoices/{id}/cancel  {reason}                       cancela
 * Gravar exige erp.invoices.manage. CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class Invoices
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user === null) {
            return;
        }
        $condominium = $this->int($_GET['condominium_id'] ?? null);
        $this->run(200, fn () => (new InvoiceService())->list($user, $condominium, [
            'competence' => $_GET['competence'] ?? null, 'status' => $_GET['status'] ?? null, 'page' => $_GET['page'] ?? 1,
        ]), false);
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user === null) {
            return;
        }
        $this->run(200, fn () => (new InvoiceService())->find($user, (int)$this->int($data['id'] ?? null)));
    }

    public function store(): void
    {
        $user = $this->authorize('manage');
        $body = $user === null ? null : $this->body();
        if ($user === null || $body === null) {
            return;
        }
        $this->run(201, fn () => (new InvoiceService())->prepare($user, $body));
    }

    public function updateItem(array $data): void
    {
        $user = $this->authorize('manage');
        $body = $user === null ? null : $this->body();
        if ($user === null || $body === null) {
            return;
        }
        $this->run(200, fn () => (new InvoiceService())->setItemAmount($user, (int)$this->int($data['id'] ?? null), (int)$this->int($data['item'] ?? null), $body['amount_cents'] ?? null));
    }

    public function issue(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user === null) {
            return;
        }
        $this->run(200, fn () => (new InvoiceService())->issue($user, (int)$this->int($data['id'] ?? null)));
    }

    public function cancel(array $data): void
    {
        $user = $this->authorize('manage');
        $body = $user === null ? null : $this->body();
        if ($user === null || $body === null) {
            return;
        }
        $this->run(200, fn () => (new InvoiceService())->cancel($user, (int)$this->int($data['id'] ?? null), is_string($body['reason'] ?? null) ? $body['reason'] : ''));
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Fatura ou condomínio não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Um lançamento da fatura não passou nas regras.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryLocked | DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'invoice_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.invoices.view', $user) || ($action === 'manage' && !Access::can('erp.invoices.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function body(): ?array
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return null;
        }
        return $body;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
