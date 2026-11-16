<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitMoveTerms as Service;
use Source\Support\Access;

/**
 * Termo de entrada e de saída (revisão de 15/11, etapa 2, ADR 0311):
 *  GET /api/v1/unit-move-terms?condominium_id=&kind=   texto em vigor (sem condominium_id = geral da Connect)
 *  PUT /api/v1/unit-move-terms {condominium_id|null, kind, body}  grava o texto
 *  GET /api/v1/unit-move-terms/render?unit_id=&resident_id=&kind=  termo com as palavras-chave trocadas (HTML escapado)
 * Texto: erp.access + erp.units.view + erp.units.manage; o geral exige acesso a todos os condomínios.
 * Termo: erp.access + erp.units.view + erp.residents.view. Fora do escopo = 404. CSRF no ApiAuthenticated.
 */
final class UnitMoveTerms
{
    public function show(): void
    {
        $user = $this->authorize(['erp.units.manage']);
        if ($user !== null) {
            $this->run(fn () => (new Service())->forEdit($user, self::condominium($_GET['condominium_id'] ?? null), (string)($_GET['kind'] ?? '')));
        }
    }

    public function update(): void
    {
        $user = $this->authorize(['erp.units.manage']);
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $this->run(fn () => (new Service())->save($user, self::condominium($body['condominium_id'] ?? null), (string)($body['kind'] ?? ''), $body['body'] ?? null));
    }

    public function render(): void
    {
        $user = $this->authorize(['erp.residents.view']);
        if ($user === null) {
            return;
        }
        $unit = filter_var($_GET['unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $resident = filter_var($_GET['resident_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($unit === false || $unit === null) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe a unidade.', 'fields' => ['unit_id' => 'Informe a unidade.']]]);
            return;
        }
        $this->run(fn () => (new Service())->render($user, (int)$unit, $resident === false ? null : $resident, (string)($_GET['kind'] ?? '')));
    }

    private function run(callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.'), 'fields' => $exception->errors]]);
        }
    }

    private static function condominium(mixed $value): ?int
    {
        $id = is_int($value) ? $value : filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) && $id > 0 ? $id : null;
    }

    /** @param list<string> $extra */
    private function authorize(array $extra): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        foreach (array_merge(['erp.access', 'erp.units.view'], $extra) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return null;
            }
        }
        return $user;
    }
}
