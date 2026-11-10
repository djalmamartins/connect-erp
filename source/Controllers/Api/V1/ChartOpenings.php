<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ChartOpenings as Service;
use Source\Support\Access;

/**
 * Saldo inicial do plano de contas (AP-161, ADR 0166), como o do centro de custo (AP-162):
 *  GET  /api/v1/chart-account-openings?condominium_id=
 *  POST /api/v1/chart-account-openings {condominium_id, chart_account_id, opened_on, amount_cents, notes}
 * Ver exige erp.settings.view; gravar, erp.settings.manage. CSRF e sessão no middleware ApiAuthenticated;
 * a gravação é auditada no service. Fora do escopo = 404; inválido (inclusive conta sintética) = 422.
 */
final class ChartOpenings
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(fn (): array => (new Service())->list($user, $this->required($_GET['condominium_id'] ?? null)));
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
        $this->run(fn (): array => (new Service())->set($user, $this->required($body['condominium_id'] ?? null), (int)($this->int($body['chart_account_id'] ?? null) ?? 0),
            $body['opened_on'] ?? null, $body['amount_cents'] ?? null, $body['notes'] ?? null));
    }

    private function run(callable $action): void
    {
        try {
            $data = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }

    private function authorize(bool $manage): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.settings.view', $user) || ($manage && !Access::can('erp.settings.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function required(mixed $value): int
    {
        return $this->int($value) ?? throw new InvalidArgumentException('Escolha o condomínio.');
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
