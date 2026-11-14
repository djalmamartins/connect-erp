<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\ManagementFees as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Honorários da administradora e pedidos de NFS-e (ADR 0402):
 *  GET  /api/v1/management-fees?competence=AAAA-MM          contratos do escopo, total mensal e pedido da competência (view)
 *  POST /api/v1/condominiums/{id}/management-fee            cria ou altera o contrato do condomínio (manage)
 *  GET  /api/v1/condominiums/{id}/nfse-requests             pedidos de nota do condomínio (view)
 *  POST /api/v1/condominiums/{id}/nfse-requests {competence} pede a nota; exige nfse.enabled ligada (manage)
 *  POST /api/v1/nfse-requests/{id}/cancel {reason}          cancela pedido ainda não emitido (manage)
 * Exige erp.access e erp.management_fees.view (+ .manage para gravar); fail-closed. CSRF pelo ApiAuthenticated.
 * Fora do escopo = 404; entrada inválida = 422; chave desligada, pedido repetido ou situação mudada = 409.
 */
final class ManagementFees
{
    public function index(): void
    {
        $this->run('view', function (Service $service, User $user): array {
            $competence = isset($_GET['competence']) && is_string($_GET['competence']) ? $_GET['competence'] : null;
            return $service->overview($user, $competence);
        });
    }

    public function save(array $data): void
    {
        $this->run('manage', fn (Service $service, User $user): array => $service->save($user, $this->id($data), $this->body()));
    }

    public function requests(array $data): void
    {
        $this->run('view', fn (Service $service, User $user): array => $service->requests($user, $this->id($data)));
    }

    public function request(array $data): void
    {
        $this->run('manage', fn (Service $service, User $user): array => $service->request($user, $this->id($data), $this->body()['competence'] ?? null), 201);
    }

    public function cancel(array $data): void
    {
        $this->run('manage', fn (Service $service, User $user): array => $service->cancel($user, $this->id($data), $this->body()['reason'] ?? null));
    }

    private function run(string $level, callable $action, int $status = 200): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $allowed = Access::can('erp.access', $user) && Access::can('erp.management_fees.view', $user)
            && ($level === 'view' || Access::can('erp.management_fees.manage', $user));
        if (!$allowed) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        try {
            $result = $action(new Service(), $user);
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Dados inválidos.', 'errors' => $exception->errors]]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            throw new RegistryValidationException(['_body' => 'Corpo JSON inválido.']);
        }
        return $body;
    }

    private function id(array $data): int
    {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RegistryNotFound();
        }
        return (int)$id;
    }
}
