<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\UnitDocuments as Service;
use Source\Support\Access;

/**
 * Documentos da unidade (AP-123, 124, 125 e 130; ADR 0044):
 *  GET  /api/v1/unit-documents?condominium_id=&unit_id=&kind=&page=   emitidos (erp.unit_documents.view)
 *  POST /api/v1/unit-documents {kind, unit_id, as_of, until_competence, purpose}  emite
 *  GET  /api/v1/unit-documents/verify/{code}                          confere a autenticidade
 *  GET  /api/v1/unit-documents/{id}                                   documento com o conteúdo
 *  POST /api/v1/unit-documents/{id}/cancel {reason}
 * Emitir e cancelar exigem erp.unit_documents.manage. CSRF e sessão no middleware ApiAuthenticated.
 */
final class UnitDocuments
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), [
                'kind' => $_GET['kind'] ?? null, 'unit_id' => $_GET['unit_id'] ?? null, 'page' => $_GET['page'] ?? 1,
            ]), false);
        }
    }

    public function show(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->find($user, (int)$this->int($data['id'] ?? null)));
        }
    }

    public function verify(array $data): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->verify($user, is_string($data['code'] ?? null) ? $data['code'] : ''));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->issue($user, $body));
        }
    }

    public function cancel(array $data): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->cancel($user, (int)$this->int($data['id'] ?? null), is_string($body['reason'] ?? null) ? $body['reason'] : ''));
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Documento ou unidade não encontrado no seu escopo.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'document_refused', 'message' => $exception->getMessage()]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.unit_documents.view', $user) || ($action === 'manage' && !Access::can('erp.unit_documents.manage', $user))) {
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
