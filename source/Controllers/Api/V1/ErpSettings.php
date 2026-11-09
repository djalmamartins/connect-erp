<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ErpSettings as Service;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Support\Access;

/**
 * Configurações do ERP (ADR 0085):
 *  GET  /api/v1/settings?condominium_id=                        parâmetros por página, com o valor efetivo e a origem
 *  POST /api/v1/settings {condominium_id, key, value}            altera (condominium_id vazio = padrão da Connect)
 *  POST /api/v1/settings/reset {condominium_id, key}             volta ao padrão de cima
 *  GET  /api/v1/cost-center-openings?condominium_id=             saldo inicial por centro de custo (AP-162)
 *  POST /api/v1/cost-center-openings {condominium_id, cost_center_id, opened_on, amount_cents, notes}
 *  GET  /api/v1/budgets?condominium_id=&year=                    previsão orçamentária com o lançado (AP-163/AP-084)
 *  POST /api/v1/budgets {condominium_id, year, chart_account_id, months[12]}
 *  POST /api/v1/unit-fractions {condominium_id, fractions{unit_id: fração}}   frações em lote (AP-167)
 * Ver exige erp.settings.view; gravar, erp.settings.manage (as frações também erp.units.manage).
 * Padrão da Connect só com acesso a todos os condomínios. CSRF e sessão no middleware ApiAuthenticated.
 */
final class ErpSettings
{
    public function index(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->list($user, $this->int($_GET['condominium_id'] ?? null)));
        }
    }

    public function store(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->set($user, $this->int($body['condominium_id'] ?? null), (string)($body['key'] ?? ''), $body['value'] ?? null));
        }
    }

    public function reset(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->reset($user, $this->int($body['condominium_id'] ?? null), (string)($body['key'] ?? '')));
        }
    }

    public function openings(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->openings($user, $this->required($_GET['condominium_id'] ?? null)));
        }
    }

    public function storeOpening(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->setOpening($user, $this->required($body['condominium_id'] ?? null), (int)$this->int($body['cost_center_id'] ?? null),
                $body['opened_on'] ?? null, $body['amount_cents'] ?? null, $body['notes'] ?? null));
        }
    }

    public function budget(): void
    {
        $user = $this->authorize(false);
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->budget($user, $this->required($_GET['condominium_id'] ?? null), (int)($this->int($_GET['year'] ?? null) ?? date('Y'))));
        }
    }

    public function storeBudget(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->setBudget($user, $this->required($body['condominium_id'] ?? null), (int)$this->int($body['year'] ?? null),
                (int)$this->int($body['chart_account_id'] ?? null), is_array($body['months'] ?? null) ? $body['months'] : []));
        }
    }

    public function fractions(): void
    {
        [$user, $body] = $this->write('erp.units.manage');
        if ($user !== null) {
            $this->run(200, fn () => (new Service())->setFractions($user, $this->required($body['condominium_id'] ?? null), is_array($body['fractions'] ?? null) ? $body['fractions'] : []));
        }
    }

    private function run(int $status, callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send($status, ['data' => $result]);
        } catch (RegistryNotFound | CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (RegistryForbidden) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'O padrão da Connect só muda com acesso a todos os condomínios.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (DomainException $exception) {
            JsonResponse::send(409, ['error' => ['code' => 'settings_conflict', 'message' => $exception->getMessage()]]);
        }
    }

    /** @return array{0: ?User, 1: array} */
    private function write(?string $extra = null): array
    {
        $user = $this->authorize(true, $extra);
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

    private function authorize(bool $manage, ?string $extra = null): ?User
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return null;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.settings.view', $user) || ($manage && !Access::can('erp.settings.manage', $user))
            || ($extra !== null && !Access::can($extra, $user))) {
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
