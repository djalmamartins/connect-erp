<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ChartReports as Charts;
use Source\Support\Access;

/**
 * Dados dos gráficos (ADR 0083), somente leitura, em séries mensais (from e to em AAAA-MM, até 24):
 *  GET /api/v1/charts/general         AP-102  receitas, despesas, resultado e vencido     (erp.entries.view)
 *  GET /api/v1/charts/accounts        AP-103  por conta do plano (nature=receita|despesa)  (erp.entries.view, erp.chart.view)
 *  GET /api/v1/charts/consumption     AP-104 (unit_id) e AP-105  consumo por tipo          (erp.meter_readings.view)
 *  GET /api/v1/charts/cash            AP-106  recebido, pago e saldo do mês (caixa)        (erp.entries.view)
 *  GET /api/v1/charts/delinquency     AP-107  cobrado, vencido e taxa (%)                  (erp.agreements.view)
 *  GET /api/v1/charts/bank-accounts   AP-108  saldo do mês por conta bancária              (erp.entries.view, erp.bank_accounts.view)
 *  GET /api/v1/charts/common-areas    AP-109  reservas por área comum                      (erp.reservations.view)
 * condominium_id vazio = todos do escopo.
 */
final class ChartReports
{
    public function general(): void
    {
        $this->run(['erp.entries.view'], fn (Charts $c, User $u, ?int $id): array => $c->general($u, $id, $this->text('from'), $this->text('to')));
    }

    public function accounts(): void
    {
        $this->run(['erp.entries.view', 'erp.chart.view'], fn (Charts $c, User $u, ?int $id): array => $c->accounts($u, $id, $this->text('from'), $this->text('to'),
            $this->text('nature') ?: 'despesa'));
    }

    public function consumption(): void
    {
        $this->run(['erp.meter_readings.view'], fn (Charts $c, User $u, ?int $id): array => $c->consumption($u, $id, $this->text('from'), $this->text('to'),
            $this->int($_GET['unit_id'] ?? null)));
    }

    public function cash(): void
    {
        $this->run(['erp.entries.view'], fn (Charts $c, User $u, ?int $id): array => $c->cash($u, $id, $this->text('from'), $this->text('to')));
    }

    public function delinquency(): void
    {
        $this->run(['erp.agreements.view'], fn (Charts $c, User $u, ?int $id): array => $c->delinquency($u, $id, $this->text('from'), $this->text('to')));
    }

    public function bankAccounts(): void
    {
        $this->run(['erp.entries.view', 'erp.bank_accounts.view'], fn (Charts $c, User $u, ?int $id): array => $c->bankAccounts($u, $id, $this->text('from'), $this->text('to')));
    }

    public function commonAreas(): void
    {
        $this->run(['erp.reservations.view'], fn (Charts $c, User $u, ?int $id): array => $c->commonAreas($u, $id, $this->text('from'), $this->text('to')));
    }

    /** @param list<string> $permissions */
    private function run(array $permissions, callable $chart): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        foreach (array_merge(['erp.access'], $permissions) as $permission) {
            if (!Access::can($permission, $user)) {
                JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
                return;
            }
        }
        try {
            $data = $chart(new Charts(), $user, $this->int($_GET['condominium_id'] ?? null));
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }

    private function text(string $name): string
    {
        return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
