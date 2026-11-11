<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Antivirus\UploadScanUnavailable;
use Source\Services\Erp\EntryAttachments as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;
use Source\Support\AppLogger;

/**
 * Anexos dos lançamentos (ADR 0174):
 *  GET    /api/v1/entries/{id}/attachments                          lista (erp.entries.view)
 *  POST   /api/v1/entries/{id}/attachments  multipart: kind, file    anexa (erp.entries.manage)
 *  GET    /api/v1/entries/{id}/attachments/{attachment}/download    baixa como anexo (erp.entries.view)
 *  DELETE /api/v1/entries/{id}/attachments/{attachment}             remove o vínculo (erp.entries.manage); competência fechada = 409
 * kind: nota_fiscal, comprovante, recibo ou outro. CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class EntryAttachments
{
    public function index(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->id($data, 'id')));
        }
    }

    public function store(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $upload = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $this->run(201, fn () => (new Service())->attach($user, $this->id($data, 'id'), $upload, $_POST['kind'] ?? null, $_POST['title'] ?? null));
        }
    }

    public function destroy(array $data): void
    {
        $user = $this->authorize('manage');
        if ($user !== null) {
            $this->run(204, function () use ($user, $data): ?array {
                (new Service())->remove($user, $this->id($data, 'id'), $this->id($data, 'attachment'));
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
            $file = (new Service())->file($user, $this->id($data, 'id'), $this->id($data, 'attachment'));
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Anexo não encontrado no seu escopo.']]);
            return;
        } catch (DomainException $exception) {   // ADR 0160: bloqueado pelo antivírus
            JsonResponse::send(403, ['error' => ['code' => 'file_blocked', 'message' => $exception->getMessage()]]);
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

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            if ($status === 204) {
                http_response_code(204);
                return;
            }
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Lançamento ou anexo não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        } catch (UploadScanUnavailable $exception) {   // ADR 0098: modo enforce sem antivírus, falha fechada
            JsonResponse::send(503, ['error' => ['code' => 'scan_unavailable', 'message' => $exception->getMessage()]]);
        } catch (\RuntimeException $exception) {
            AppLogger::exception($exception, 'erp', ['event_type' => 'entry_attachment_failed']);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || ($action === 'manage' && !Access::can('erp.entries.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function id(array $data, string $key): int
    {
        return (int)(filter_var($data[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0);
    }
}
