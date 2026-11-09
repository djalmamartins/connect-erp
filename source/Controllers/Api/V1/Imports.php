<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Imports\Imports as Service;
use Source\Services\Erp\Registry\RegistryLocked;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Importações (ADR 0058):
 *  GET  /api/v1/imports?condominium_id=&kind=                         lista (erp.imports.view)
 *  POST /api/v1/imports/preview  multipart {kind, condominium_id, bank_account_id (ofx), file}   prévia
 *  GET  /api/v1/imports/{id}                                          importação (OFX: com as linhas)
 *  POST /api/v1/imports/{id}/apply                                    aplica (revalida o arquivo inteiro)
 *  GET  /api/v1/bank-statement-lines?condominium_id=                  linhas do extrato pendentes
 *  POST /api/v1/bank-statement-lines/{id}/resolve {action: match|ignore, entry_id, note}
 * Gravar exige erp.imports.manage e a permissão do destino (lançamentos ou unidades). CSRF e sessão no
 * middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class Imports
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), ['kind' => $_GET['kind'] ?? null]), false);
        }
    }

    public function preview(): void
    {
        $kind = is_string($_POST['kind'] ?? null) ? $_POST['kind'] : '';
        $user = $this->authorize(true, Service::KINDS[$kind]['permission'] ?? null);
        if ($user !== null) {
            $upload = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $this->run(201, fn () => (new Service())->preview($user, $kind, $_POST, $upload));
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function apply(array $data): void
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return;
        }
        $this->run(200, function () use ($user, $data): array {
            $service = new Service();
            $id = (int)$this->int($data['id'] ?? null);
            $permission = Service::KINDS[$service->find($user, $id)['kind']]['permission'];
            if (!Access::can($permission, $user)) {
                throw new \Source\Services\Erp\Registry\RegistryForbidden();
            }
            return $service->apply($user, $id);
        });
    }

    public function pendingLines(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->pendingLines($user, $this->int($_GET['condominium_id'] ?? null)), false);
        }
    }

    public function resolveLine(array $data): void
    {
        $user = $this->authorize(true, 'erp.entries.manage');
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $this->run(200, fn () => (new Service())->resolveLine($user, (int)$this->int($data['id'] ?? null),
            is_string($body['action'] ?? null) ? $body['action'] : '', $body['entry_id'] ?? null, $body['note'] ?? null));
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Importação ou condomínio não encontrado no seu escopo.']]);
        } catch (\Source\Services\Erp\Registry\RegistryForbidden) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão para gravar este tipo de importação.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Uma linha não passou nas regras do cadastro; nada foi gravado.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (RegistryLocked | DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'import_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(bool $manage, ?string $target = null): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.imports.view', $user) || ($manage && !Access::can('erp.imports.manage', $user))
            || ($target !== null && !Access::can($target, $user))) {
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
