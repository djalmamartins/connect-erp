<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Inspections\InspectionError;
use Source\Services\Inspections\InspectionService;

/**
 * API REST da vistoria presencial (docs/api/openapi.yaml, tag Inspections).
 * Sessão autenticada e CSRF nos métodos de escrita ficam no middleware ApiAuthenticated;
 * permissão e escopo de condomínio ficam no InspectionService (mesmas regras da tela).
 * Corpo JSON nas escritas, exceto o envio de foto (multipart/form-data).
 */
final class Inspections
{
    /** GET /api/v1/inspection-templates */
    public function templates(): void
    {
        $this->run(function (User $user, InspectionService $service): array {
            if (!$service->can(InspectionService::VIEW, $user)) {
                throw InspectionError::forbidden();
            }
            return [200, ['data' => $service->templates()]];
        });
    }

    /** GET /api/v1/inspections/schedule */
    public function schedule(): void
    {
        $this->run(function (User $user, InspectionService $service): array {
            if (!$service->can(InspectionService::VIEW, $user)) {
                throw InspectionError::forbidden();
            }
            return [200, ['data' => $service->board($user)]];
        });
    }

    /** GET /api/v1/inspections?condominium_id=&status= */
    public function index(): void
    {
        $this->run(fn(User $user, InspectionService $service): array => [200, ['data' => $service->recent($user, [
            'condominium_id' => $_GET['condominium_id'] ?? null, 'status' => $_GET['status'] ?? null,
        ], (int)($_GET['limit'] ?? 50))]]);
    }

    /** POST /api/v1/inspections — check-in */
    public function store(): void
    {
        $this->run(function (User $user, InspectionService $service): array {
            $id = $service->start($user, $this->body());
            return [201, ['data' => $this->public($service->get($user, $id))]];
        });
    }

    /** GET /api/v1/inspections/{id} */
    public function show(array $data): void
    {
        $this->run(fn(User $user, InspectionService $service): array => [200, ['data' => $this->public($service->get($user, (int)($data['id'] ?? 0)))]]);
    }

    /** PUT /api/v1/inspections/{id}/items/{item} */
    public function answer(array $data): void
    {
        $this->run(function (User $user, InspectionService $service) use ($data): array {
            $id = (int)($data['id'] ?? 0);
            $service->answer($user, $id, (int)($data['item'] ?? 0), $this->body());
            return [200, ['data' => $this->public($service->get($user, $id))]];
        });
    }

    /** POST /api/v1/inspections/{id}/items/{item}/photos (multipart: photo, latitude, longitude) */
    public function photo(array $data): void
    {
        $this->run(function (User $user, InspectionService $service) use ($data): array {
            $photoId = $service->addPhoto($user, (int)($data['id'] ?? 0), (int)($data['item'] ?? 0), is_array($_FILES['photo'] ?? null) ? $_FILES['photo'] : [], $_POST);
            return [201, ['data' => ['id' => $photoId]]];
        });
    }

    /**
     * GET /api/v1/inspections/{id}/photos/{photo} — a própria foto (JPEG sem EXIF), para o app
     * mostrar com o token da equipe (ADR 0674). Mesmo escopo da tela: fora dele, 404.
     */
    public function photoFile(array $data): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        try {
            $file = (new InspectionService())->photoFile($user, (int)($data['id'] ?? 0), (int)($data['photo'] ?? 0));
        } catch (InspectionError $error) {
            JsonResponse::send($error->status, ['error' => ['code' => $error->errorCode, 'message' => $error->getMessage()]]);
            return;
        }
        foreach (self::photoHeaders($file) as $line) {
            header($line);
        }
        readfile($file['path']);
    }

    /**
     * Cabeçalhos da foto: só imagem (nosniff, CSP sem nada), cache privado (nunca em proxy) e ETag do conteúdo.
     *
     * @param array{path: string, mime: string, sha256: string} $file
     * @return list<string>
     */
    public static function photoHeaders(array $file): array
    {
        $mime = in_array($file['mime'], ['image/jpeg', 'image/png', 'image/webp'], true) ? $file['mime'] : 'application/octet-stream';
        return [
            'Content-Type: ' . $mime,
            'Content-Length: ' . (string)filesize($file['path']),
            'Cache-Control: private, max-age=3600',
            'ETag: "' . (preg_match('/^[a-f0-9]{64}$/', $file['sha256']) === 1 ? $file['sha256'] : '') . '"',
            'X-Content-Type-Options: nosniff',
            "Content-Security-Policy: default-src 'none'",
        ];
    }

    /** POST /api/v1/inspections/{id}/complete */
    public function complete(array $data): void
    {
        $this->run(function (User $user, InspectionService $service) use ($data): array {
            $id = (int)($data['id'] ?? 0);
            $service->complete($user, $id, (string)($this->body()['summary'] ?? ''));
            return [200, ['data' => $this->public($service->get($user, $id))]];
        });
    }

    /** @param callable(User, InspectionService): array{0: int, 1: array} $action */
    private function run(callable $action): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        try {
            [$status, $payload] = $action($user, new InspectionService());
            JsonResponse::send($status, $payload);
        } catch (InspectionError $error) {
            $body = ['code' => $error->errorCode, 'message' => $error->getMessage()];
            if ($error->errors !== []) {
                $body['fields'] = $error->errors;
            }
            JsonResponse::send($error->status, ['error' => $body]);
        } catch (\JsonException) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
        }
    }

    /** @return array<string, mixed> */
    private function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if (trim($raw) === '') {
            return [];
        }
        $body = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        return is_array($body) ? $body : [];
    }

    /** Remove o que só a tela usa e acrescenta o endereço das fotos. */
    private function public(array $inspection): array
    {
        foreach ($inspection['items'] as &$item) {
            foreach ($item['photos'] as &$photo) {
                $photo['url'] = erp_url('/operacional/vistorias/' . $inspection['id'] . '/fotos/' . $photo['id']);
                $photo['api_path'] = '/inspections/' . $inspection['id'] . '/photos/' . $photo['id'];
            }
        }
        unset($item, $photo);
        $inspection['deliveries'] = array_map(static fn(array $d): array => ['recipient_email' => $d['recipient_email'], 'queued_at' => $d['queued_at']], $inspection['deliveries']);
        return $inspection;
    }
}
