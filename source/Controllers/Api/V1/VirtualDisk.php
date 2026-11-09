<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\VirtualDisk as Service;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Disco Virtual (AP-122, ADR 0047):
 *  GET    /api/v1/documents?condominium_id=&folder=&q=&page=   lista (erp.documents.view)
 *  POST   /api/v1/documents  multipart: condominium_id, folder, title, file   envia (erp.documents.manage)
 *  GET    /api/v1/documents/{id}                               dados do arquivo
 *  GET    /api/v1/documents/{id}/download                      baixa como anexo (erp.documents.view)
 *  DELETE /api/v1/documents/{id}                               exclusão lógica (erp.documents.manage)
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class VirtualDisk
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $this->run(200, fn () => (new Service())->list($user, $condominium === false || $condominium === null ? null : (int)$condominium, [
                'folder' => $_GET['folder'] ?? null, 'q' => $_GET['q'] ?? null, 'page' => $_GET['page'] ?? 1,
            ]), false);
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, $this->id($data)));
        }
    }

    public function store(): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $upload = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $this->run(201, fn () => (new Service())->upload($user, $upload, $_POST));
        }
    }

    public function destroy(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $this->run(204, function () use ($user, $data): ?array {
                (new Service())->delete($user, $this->id($data));
                return null;
            });
        }
    }

    public function download(array $data): void
    {
        $user = $this->authorize('view');
        if ($user === null) {
            return;
        }
        try {
            $file = (new Service())->file($user, $this->id($data));
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Arquivo não encontrado no seu escopo.']]);
            return;
        }
        $ascii = (string)preg_replace('/[^A-Za-z0-9._ -]+/', '_', $file['name']);
        header('Content-Type: ' . $file['mime']);
        header('Content-Length: ' . $file['size']);
        header('Content-Disposition: attachment; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; sandbox");
        readfile($file['path']);
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            if ($status === 204) {
                http_response_code(204);
                return;
            }
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Arquivo ou condomínio não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'duplicate_file', 'message' => $exception->getMessage()]]);
        } catch (\RuntimeException $exception) {
            AppLogger::exception($exception, 'erp', ['event_type' => 'virtual_disk_failed']);
            JsonResponse::send(500, ['error' => ['code' => 'storage_failed', 'message' => 'Não foi possível guardar o arquivo.']]);
        }
    }

    private function authorize(string $action): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.documents.view', $user) || ($action === 'manage' && !Access::can('erp.documents.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function id(array $data): int
    {
        return (int)(filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
