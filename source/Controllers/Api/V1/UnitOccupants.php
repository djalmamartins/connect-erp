<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitOccupantChange;
use Source\Support\Access;

/**
 * Inquilino e proprietário da unidade (revisão de 15/11, etapa 2, ADR 0311; modelos 4, 5 e 6):
 *  GET  /api/v1/units/{id}/occupants        proprietários e inquilinos atuais e quem paga a unidade
 *  POST /api/v1/units/{id}/occupant-change  {role, person_id | person{name, document, email, phone}, history, exit_on, entry_on, invoices}
 * Exige erp.access, erp.units.view e erp.residents.view; trocar exige também erp.residents.manage e, com invoices
 * diferente de none, erp.entries.manage (fail-closed). CSRF pelo ApiAuthenticated. Fora do escopo = 404; 422 sem gravar.
 */
final class UnitOccupants
{
    public function show(array $data): void
    {
        $user = $this->authorize([]);
        if ($user !== null) {
            $this->run(fn () => (new UnitOccupantChange())->current($user, (int)($data['id'] ?? 0)));
        }
    }

    public function change(array $data): void
    {
        $body = json_decode((string)file_get_contents('php://input'), true);
        $needs = ['erp.residents.manage'];
        if (is_array($body) && ($body['invoices'] ?? 'none') !== 'none') {
            $needs[] = 'erp.entries.manage';
        }
        $user = $this->authorize($needs);
        if ($user === null) {
            return;
        }
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        $this->run(fn () => (new UnitOccupantChange())->apply($user, (int)($data['id'] ?? 0), $body));
    }

    private function run(callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Unidade ou pessoa não encontrada no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.'), 'fields' => $exception->errors]]);
        }
    }

    /** @param list<string> $extra */
    private function authorize(array $extra): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        foreach (array_merge(['erp.access', 'erp.units.view', 'erp.residents.view'], $extra) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return null;
            }
        }
        return $user;
    }
}
