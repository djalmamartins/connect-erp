<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\VehicleUnitRelease as Service;
use Source\Support\Access;

/**
 * Inativação em lote dos veículos de unidades sem morador ativo (ADR 0267):
 *  POST /api/v1/vehicles/deactivate-unattached {condominium_id, vehicle_ids?, reason, dry_run?}
 * Exige erp.access, erp.vehicles.view e erp.vehicles.manage, as mesmas do cadastro, e erp.residents.view (o morador
 * ativo da unidade vem do cadastro de moradores); fail-closed. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada
 * inválida ou veículo recusado = 422 (nada é gravado). Nenhum aviso é enviado.
 */
final class VehicleUnitRelease
{
    public function store(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.vehicles.view', $user) || !Access::can('erp.vehicles.manage', $user)
            || !Access::can('erp.residents.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $data = (new Service())->release($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Inativação recusada: nada foi gravado.', 'errors' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
