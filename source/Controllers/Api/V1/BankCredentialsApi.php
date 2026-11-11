<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Integração do banco pela tela Integrações (ADR 0158), só sandbox:
 *  GET    /api/v1/bank-credentials                 contas do Inter no escopo e situação da credencial (erp.settings.view + erp.bank_accounts.view)
 *  POST   /api/v1/bank-credentials/{id}            {client_id, client_secret, files:[{name, content(base64)}], save_anyway?}
 *                                                  abre o pacote, testa e grava; devolve o endereço do webhook UMA vez
 *  POST   /api/v1/bank-credentials/{id}/test       testa a credencial gravada (só token)
 *  DELETE /api/v1/bank-credentials/{id}            apaga a credencial; a conta volta ao sandbox interno
 * Gravar e testar exigem erp.settings.manage e erp.bank_accounts.manage. CSRF e sessão no ApiAuthenticated.
 * Conta fora do escopo = 404. Segredos nunca voltam na resposta. Corpo até 1 MB.
 */
final class BankCredentialsApi
{
    private const MAX_BODY = 1048576;

    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new BankCredentialSetup())->accounts($user));
        }
    }

    public function store(array $data): void
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return;
        }
        $raw = (string)file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        if (strlen($raw) > self::MAX_BODY) {
            JsonResponse::send(413, ['error' => ['code' => 'payload_too_large', 'message' => 'Envio grande demais. Mande só o ZIP do banco (ou o .crt e o .key).']]);
            return;
        }
        $body = json_decode($raw, true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $base = (string)(getenv('BANK_WEBHOOK_BASE_URL') ?: url());
        $this->run(201, fn () => (new BankCredentialSetup())->connect($user, $this->id($data), $body, $base));
    }

    public function test(array $data): void
    {
        $user = $this->authorize(true);
        if ($user !== null) {
            $this->run(200, fn () => (new BankCredentialSetup())->test($user, $this->id($data)));
        }
    }

    public function destroy(array $data): void
    {
        $user = $this->authorize(true);
        if ($user !== null) {
            $this->run(200, function () use ($user, $data): array {
                (new BankCredentialSetup())->remove($user, $this->id($data));
                return ['removed' => true];
            });
        }
    }

    private function run(int $status, callable $action): void
    {
        header('Cache-Control: no-store, private');
        try {
            JsonResponse::send($status, ['data' => $action()]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'bank_credential_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        $needed = $manage ? ['erp.access', 'erp.settings.manage', 'erp.bank_accounts.manage'] : ['erp.access', 'erp.settings.view', 'erp.bank_accounts.view'];
        foreach ($needed as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return null;
            }
        }
        return $user;
    }

    private function id(array $data): int
    {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            throw new RegistryNotFound('Conta bancária não encontrada.');
        }
        return (int)$id;
    }
}
