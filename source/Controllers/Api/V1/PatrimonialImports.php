<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Imports\PatrimonialImports as Service;
use Source\Support\Access;

/**
 * Importação de lançamentos patrimoniais (ADR 0171), no mesmo fluxo de prévia e aplicação das outras
 * importações. CSRF e sessão ficam no middleware ApiAuthenticated:
 *  POST /api/v1/patrimonial-imports (multipart: condominium_id, file; cabeçalho Idempotency-Key opcional)   prévia
 *  GET  /api/v1/patrimonial-imports/{id}
 *  POST /api/v1/patrimonial-imports/{id}/apply                                                              grava tudo ou nada
 * Ver exige erp.imports.view; prévia e aplicação, também erp.imports.manage e erp.entries.manage (o destino
 * são lançamentos), como as outras importações. Fora do escopo = 404; inválido = 422; já aplicada, chave
 * repetida com outro arquivo, linha com erro ou competência fechada = 409.
 */
final class PatrimonialImports
{
    public function preview(): void
    {
        $user = $this->authorize(true);
        if ($user !== null) {
            $upload = is_array($_FILES['file'] ?? null) ? $_FILES['file'] : [];
            $key = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($_POST['idempotency_key'] ?? null);
            $this->run(201, fn (): array => (new Service())->preview($user, ['condominium_id' => $_POST['condominium_id'] ?? null, 'idempotency_key' => $key], $upload));
        }
    }

    /** @param array{id?:string} $data */
    public function show(array $data): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn (): array => (new Service())->find($user, $this->id($data)));
        }
    }

    /** @param array{id?:string} $data */
    public function apply(array $data): void
    {
        $user = $this->authorize(true);
        if ($user !== null) {
            $this->run(200, fn (): array => (new Service())->apply($user, $this->id($data)));
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Importação ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'import_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.imports.view', $user)
            || ($manage && (!Access::can('erp.imports.manage', $user) || !Access::can('erp.entries.manage', $user)))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    /** @param array{id?:string} $data */
    private function id(array $data): int
    {
        $id = filter_var($data['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $id === null ? 0 : (int)$id;
    }
}
