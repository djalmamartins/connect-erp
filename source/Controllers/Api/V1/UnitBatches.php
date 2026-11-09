<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\UnitBatch;
use Source\Support\Access;

/**
 * Cadastro rápido de unidades e fração ideal/área em grade (AP-006, ADR 0140):
 *  POST /api/v1/unit-batches/preview  {condominium_id, blocks, floor_from, floor_to, units_per_floor, digits,
 *       first_position, skip_floors, skip_numbers, extra_numbers, type, private_area}  sequência com o que já existe
 *  POST /api/v1/unit-batches          mesmo corpo; cria só as novas, todas ou nenhuma (201)
 *  GET  /api/v1/unit-measures?condominium_id=   fração ideal e área das unidades ativas, com as somas
 *  PUT  /api/v1/unit-measures         {condominium_id, units: [{id, ideal_fraction?, private_area?}]}
 *  POST /api/v1/unit-measures/distribute  {mode: equal|area, units: [{id, private_area}]}  proposta, não grava
 * Ler: erp.units.view; gravar e propor: erp.units.manage. CSRF e sessão no ApiAuthenticated. Fora do escopo = 404.
 */
final class UnitBatches
{
    public function preview(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new UnitBatch())->preview($user, $body));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new UnitBatch())->create($user, $body));
        }
    }

    public function measures(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($condominium === false || $condominium === null) {
                JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe o condomínio.', 'fields' => ['condominium_id' => 'Informe o condomínio.']]]);
                return;
            }
            $this->run(200, fn () => (new UnitBatch())->measures($user, (int)$condominium));
        }
    }

    public function saveMeasures(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $condominium = filter_var($body['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $units = $body['units'] ?? null;
            if ($condominium === false || $condominium === null || !is_array($units) || !array_is_list($units)) {
                JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Informe o condomínio e a lista de unidades.']]);
                return;
            }
            $this->run(200, fn () => (new UnitBatch())->saveMeasures($user, (int)$condominium, $units));
        }
    }

    public function distribute(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $units = $body['units'] ?? null;
            if (!is_array($units) || !array_is_list($units) || count($units) > UnitBatch::MAX_UNITS) {
                JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Envie a lista de unidades.']]);
                return;
            }
            $units = array_values(array_filter($units, 'is_array'));
            $this->run(200, fn () => ['fractions' => UnitBatch::distribute($units, (string)($body['mode'] ?? ''))]);
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => (string)(array_values($exception->errors)[0] ?? 'Dados inválidos.'), 'fields' => $exception->errors]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.units.view', $user) || ($action === 'manage' && !Access::can('erp.units.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }
}
