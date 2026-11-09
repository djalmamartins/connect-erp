<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Erp\BillingRules as Service;
use Source\Services\Erp\Registry\RegistryForbidden;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\RecurringEntries;
use Source\Support\Access;

/**
 * Regras de cobrança e índices de correção (ADR 0040):
 *  GET  /api/v1/billing-rules?condominium_id=          regra que vale (do condomínio ou o padrão)
 *  PUT  /api/v1/billing-rules {condominium_id|null, fine_bp, monthly_interest_bp, price_index, breach_days_late, breach_open_installments}
 *  GET  /api/v1/price-indexes?code=IPCA                últimos 240 meses
 *  POST /api/v1/price-indexes {code, competence, rate_percent}   lança um mês
 *  POST /api/v1/price-indexes/import {code, content}  importa "AAAA-MM;variação" (sem rede)
 * Ler exige erp.billing_rules.view; gravar, erp.billing_rules.manage. Padrão da Connect e índices
 * exigem acesso a todos os condomínios (403). CSRF e sessão no middleware ApiAuthenticated.
 */
final class BillingRules
{
    public function show(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(fn () => (new Service())->ruleFor($user, $this->int($_GET['condominium_id'] ?? null)));
        }
    }

    public function update(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn () => (new Service())->save($user, $this->int($body['condominium_id'] ?? null), $body));
        }
    }

    public function rates(): void
    {
        $user = $this->authorize('view');
        if ($user !== null) {
            $this->run(fn () => (new Service())->listRates(is_string($_GET['code'] ?? null) ? strtoupper($_GET['code']) : 'IPCA'));
        }
    }

    public function setRate(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(function () use ($user, $body): array {
                $competence = is_string($body['competence'] ?? null) ? $body['competence'] : '';
                if (!RecurringEntries::isCompetence($competence)) {
                    throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
                }
                $rates = Service::parseIndexFile($competence . ';' . (is_scalar($body['rate_percent'] ?? null) ? (string)$body['rate_percent'] : ''));
                return (new Service())->saveRates($user, is_string($body['code'] ?? null) ? strtoupper($body['code']) : 'IPCA', $rates);
            });
        }
    }

    public function import(): void
    {
        [$user, $body] = $this->write();
        if ($user !== null) {
            $this->run(fn () => (new Service())->saveRates($user, is_string($body['code'] ?? null) ? strtoupper($body['code']) : 'IPCA',
                Service::parseIndexFile(is_string($body['content'] ?? null) ? $body['content'] : '')));
        }
    }

    private function run(callable $action): void
    {
        try {
            $result = $action();
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $result]);
        } catch (RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (RegistryForbidden) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'O padrão da Connect e os índices exigem acesso a todos os condomínios.']]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
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
        if (!Access::can('erp.access', $user) || !Access::can('erp.billing_rules.view', $user) || ($action === 'manage' && !Access::can('erp.billing_rules.manage', $user))) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return null;
        }
        return $user;
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
