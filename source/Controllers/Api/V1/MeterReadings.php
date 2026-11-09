<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\MeterReadings as Service;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Access;

/**
 * Leituras e consumo (AP-042, ADR 0045):
 *  GET  /api/v1/meter-readings?condominium_id=&competence=&kind=   leituras com consumo e valor (erp.meter_readings.view)
 *  POST /api/v1/meter-readings {meter_id, competence, reading, read_on}   lança ou corrige (erp.meter_readings.manage)
 *  POST /api/v1/meter-readings/bill {condominium_id, competence, kind, chart_account_id, due_on}  gera as contas a receber
 * CSRF e sessão no middleware ApiAuthenticated. Fora do escopo = 404.
 */
final class MeterReadings
{
    public function index(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $this->run(200, fn () => (new Service())->list($user, $condominium === false || $condominium === null ? null : (int)$condominium, [
                'competence' => $_GET['competence'] ?? null, 'kind' => $_GET['kind'] ?? null,
            ]), false);
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(201, fn () => (new Service())->record($user, $body));
        }
    }

    public function bill(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->bill($user, $body));
        }
    }

    private function run(int $status, callable $action, bool $wrap = true): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, $wrap ? ['data' => $result] : $result);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Relógio ou condomínio não encontrado no seu escopo.']]);
        } catch (RegistryValidationException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => 'Uma conta a receber não passou nas regras dos lançamentos.', 'fields' => $exception->errors]]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'reading_conflict', 'message' => $exception->getMessage()]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.meter_readings.view', $user) || ($action === 'manage' && !Access::can('erp.meter_readings.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }
}
