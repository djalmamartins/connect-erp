<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryLocked;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * API REST dos cadastros do ERP (/api/v1/<recurso>), sobre o mesmo RegistryService das
 * telas. Sessão autenticada e CSRF nos métodos de escrita ficam no middleware
 * ApiAuthenticated; aqui ficam permissão por ação e o contrato JSON (docs/api/openapi.yaml).
 */
final class Registry
{
    public function index(string $slug): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'view');
        if ($user === null) {
            return;
        }
        $result = (new RegistryService())->list($user, $slug, [
            'condominium_id' => $_GET['condominium_id'] ?? null,
            'status' => $_GET['status'] ?? null,
            'filter' => $_GET['type'] ?? null,
            'q' => $_GET['q'] ?? null,
            'page' => $_GET['page'] ?? 1,
        ]);
        JsonResponse::send(200, [
            'data' => $result['data'],
            'meta' => ['total' => $result['total'], 'page' => $result['page'], 'per_page' => $result['per_page'], 'pages' => $result['pages']],
        ]);
    }

    public function show(string $slug, array $data): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'view');
        if ($user === null) {
            return;
        }
        try {
            JsonResponse::send(200, ['data' => (new RegistryService())->find($user, $slug, $this->id($data))]);
        } catch (RegistryNotFound) {
            $this->notFound();
        }
    }

    public function store(string $slug): void
    {
        $this->write($slug, null);
    }

    public function update(string $slug, array $data): void
    {
        $this->write($slug, $this->id($data));
    }

    public function destroy(string $slug, array $data): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'manage');
        if ($user === null) {
            return;
        }
        try {
            (new RegistryService())->delete($user, $slug, $this->id($data));
            http_response_code(204);
            header('Cache-Control: no-store');
        } catch (RegistryNotFound) {
            $this->notFound();
        } catch (RegistryForbidden) {
            $this->forbidden('Registro compartilhado por todos os condomínios: exige acesso a todos eles.');
        } catch (RegistryInUse) {
            JsonResponse::send(409, ['error' => ['code' => 'in_use', 'message' => 'Registro em uso por cadastros ativos vinculados. Desvincule-os antes ou inative o registro.']]);
        } catch (RegistryLocked $locked) {
            JsonResponse::send(409, ['error' => ['code' => 'closed_competence', 'message' => $locked->getMessage()]]);
        }
    }

    private function write(string $slug, ?int $id): void
    {
        $definition = RegistryService::definition($slug);
        $user = $this->authorize($definition, 'manage');
        if ($user === null) {
            return;
        }
        $body = self::body();
        if ($body === null) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $record = (new RegistryService())->save($user, $slug, $body, $id);
            JsonResponse::send($id === null ? 201 : 200, ['data' => $record]);
        } catch (RegistryNotFound) {
            $this->notFound();
        } catch (RegistryForbidden) {
            $this->forbidden('Registro compartilhado por todos os condomínios: exige acesso a todos eles.');
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Dados inválidos.', 'fields' => $exception->errors]]);
        }
    }

    /** @return array<string, mixed>|null corpo JSON (objeto) ou, sem JSON, os campos de formulário. */
    private static function body(): ?array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            return $_POST;
        }
        try {
            $decoded = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded) || array_is_list($decoded) && $decoded !== []) {
            return null;
        }
        // JSON true/false para campos booleanos; o validador aceita os dois formatos.
        return array_map(static fn ($v) => is_bool($v) ? ($v ? '1' : '0') : (is_int($v) || is_float($v) ? (string)$v : $v), $decoded);
    }

    private function authorize(array $definition, string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        $permission = $definition['permission'];
        if (!Access::can('erp.access', $user) || !Access::can($permission . '.view', $user) || ($action === 'manage' && !Access::can($permission . '.manage', $user))) {
            $this->forbidden('Sem permissão.');
            return null;
        }
        return $user;
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }

    private function notFound(): void
    {
        JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Registro não encontrado.']]);
    }

    private function forbidden(string $message): void
    {
        JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => $message]]);
    }
}
