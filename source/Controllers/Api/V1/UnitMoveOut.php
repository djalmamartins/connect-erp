<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitMoveOut as Service;
use Source\Support\Access;

/**
 * Saída de moradores da unidade (ADR 0206):
 *  POST /api/v1/units/move-out {condominium_id, unit_id, relationships[]?, ends_on, reason, include_vehicles?, include_pets?, dry_run?}
 * Exige erp.access, erp.residents.view e erp.residents.manage; com include_vehicles, também erp.vehicles.manage; com
 * include_pets, também erp.pets.manage (fail-closed). CSRF pelo ApiAuthenticated. Condomínio fora do escopo ou unidade de
 * outro condomínio = 404; entrada inválida ou registro recusado pelo formulário = 422 (nada é gravado).
 */
final class UnitMoveOut
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        $body = is_array($body) ? $body : null;
        $needs = ['erp.access', 'erp.residents.view', 'erp.residents.manage'];
        if ($body !== null && filter_var($body['include_vehicles'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $needs[] = 'erp.vehicles.manage';
        }
        if ($body !== null && filter_var($body['include_pets'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
            $needs[] = 'erp.pets.manage';
        }
        foreach ($needs as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        if ($body === null) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->moveOut($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Registro recusado: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Unidade não encontrada no seu escopo.']]);
        }
    }
}
