<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\LocalHolidays as Service;
use Source\Support\Access;

/**
 * Feriados do estado e da cidade no prazo da prestação de contas (ADR 0306). CSRF e sessão no ApiAuthenticated:
 *  GET    /api/v1/local-holidays?condominium_id=&competence=               feriados do lugar do condomínio e o prazo do livro
 *  POST   /api/v1/local-holidays {condominium_id, scope, date, name, every_year?}   cadastra (estado ou cidade do condomínio)
 *  DELETE /api/v1/local-holidays/{id}?condominium_id=                     remove
 * Ver exige erp.closing.view; cadastrar e remover, também erp.closing.manage. Fora do escopo = 404; inválido = 422;
 * repetido = 409.
 */
final class LocalHolidays
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $competence = is_string($_GET['competence'] ?? null) ? $_GET['competence'] : null;
            $this->run(fn (): array => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null), $competence));
        }
    }

    public function store(): void
    {
        $user = $this->authorize(true);
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $this->run(fn (): array => (new Service())->add($user, $this->int($body['condominium_id'] ?? null), $body), 201);
    }

    /** @param array{id?:string} $data */
    public function destroy(array $data): void
    {
        $user = $this->authorize(true);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->remove($user, $this->int($_GET['condominium_id'] ?? null), $this->int($data['id'] ?? null) ?? 0));
        }
    }

    private function run(callable $action, int $status = 200): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Feriado ou condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'conflict', 'message' => $exception->getMessage()]]);
        }
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.closing.view', $user) || ($manage && !Access::can('erp.closing.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function int(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $id === null ? null : (int)$id;
    }
}
