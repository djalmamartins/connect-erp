<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitBulkSettings as Service;
use Source\Support\Access;

/**
 * Configuração das unidades em massa (revisão de 15/11, etapa 2, ADR 0311):
 *  GET  /api/v1/units/bulk-settings   campos aceitos e imobiliárias ativas para o seletor
 *  POST /api/v1/units/bulk-settings   {condominium_id, unit_ids[], settings{campo: valor}}  grava só o que veio
 * Exige erp.access, erp.units.view e erp.units.manage. CSRF pelo ApiAuthenticated. Fora do escopo = 404; entrada
 * inválida ou unidade de outro condomínio = 422 e nada é gravado.
 */
final class UnitBulkSettings
{
    public function options(): void
    {
        if ($this->authorize() !== null) {
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => ['settings' => array_merge(Service::SETTINGS, ['partner_agency_id']), 'max_units' => Service::MAX_UNITS,
                'agencies' => (new Service())->agencies()]]);
        }
    }

    public function store(): void
    {
        $user = $this->authorize();
        if ($user === null) {
            return;
        }
        $body = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($body)) {
            JsonResponse::send(400, ['error' => ['code' => 'invalid_json', 'message' => 'Corpo JSON inválido.']]);
            return;
        }
        try {
            $result = (new Service())->apply($user, $body);
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.'), 'fields' => $exception->errors]]);
        }
    }

    private function authorize(): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.units.view', $user) || !Access::can('erp.units.manage', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }
}
