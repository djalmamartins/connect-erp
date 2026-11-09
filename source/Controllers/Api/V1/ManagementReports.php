<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ManagementReports as Reports;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatórios gerenciais (ADR 0082), somente leitura; ?format=csv baixa a mesma consulta em CSV seguro:
 *  GET /api/v1/reports/consumption?from=&to=&by=unit                 consumo geral (AP-045) e por unidade (AP-046)
 *  GET /api/v1/reports/cost-centers?from=&to=                        centros de custo (AP-049)
 *  GET /api/v1/reports/comparison?from_a=&to_a=&from_b=&to_b=        comparativo (AP-050)
 *  GET /api/v1/reports/financial-statement?from=&to=&view=           demonstrativos (AP-052 a AP-054)
 *  GET /api/v1/reports/account-statement?bank_account_id=&from=&to=  extrato da conta (AP-056)
 *  GET /api/v1/reports/apportionment?condominium_id=&competence=&view=&compare_with=&unit_id=   rateio (AP-062 a AP-067)
 *  GET /api/v1/reports/entries?from=&to=&nature=&situation=&detailed=1  lançamentos (AP-068/069)
 *  GET /api/v1/reports/bank-charges?from=&to=&status=                boletos (AP-070)
 * condominium_id vazio = todos do escopo (o rateio exige um condomínio).
 */
final class ManagementReports
{
    public function consumption(): void
    {
        $byUnit = $this->text('by') === 'unit';
        $this->run('consumption', ['erp.meter_readings.view'], fn (Reports $r, User $u, ?int $c): array => $r->consumption($u, $c, $this->text('from'), $this->text('to'), $byUnit));
    }

    public function costCenters(): void
    {
        $this->run('cost-centers', ['erp.entries.view', 'erp.cost_centers.view'], fn (Reports $r, User $u, ?int $c): array => $r->costCenters($u, $c, $this->text('from'), $this->text('to')));
    }

    public function comparison(): void
    {
        $this->run('comparison', ['erp.entries.view', 'erp.chart.view'], fn (Reports $r, User $u, ?int $c): array => $r->comparison($u, $c,
            $this->text('from_a'), $this->text('to_a'), $this->text('from_b'), $this->text('to_b')));
    }

    public function statement(): void
    {
        $view = $this->text('view') ?: 'accounts';
        $this->run('statement-' . (in_array($view, ['accounts', 'analytic', 'situation'], true) ? $view : 'accounts'), ['erp.entries.view', 'erp.chart.view'],
            fn (Reports $r, User $u, ?int $c): array => $r->statement($u, $c, $this->text('from'), $this->text('to'), $view));
    }

    public function accountStatement(): void
    {
        $this->run('account-statement', ['erp.entries.view', 'erp.bank_accounts.view'], fn (Reports $r, User $u, ?int $c): array => $r->accountStatement($u,
            (int)$this->int($_GET['bank_account_id'] ?? null), $this->text('from'), $this->text('to')));
    }

    public function apportionment(): void
    {
        $view = $this->text('view') ?: 'unit';
        $this->run('apportionment-' . (in_array($view, Reports::APPORTIONMENT_VIEWS, true) ? $view : 'unit'), ['erp.entries.view', 'erp.units.view'],
            function (Reports $r, User $u, ?int $c) use ($view): array {
                if ($c === null) {
                    throw new InvalidArgumentException('Escolha o condomínio do rateio.');
                }
                return $r->apportionment($u, $c, $this->text('competence'), $view, $this->text('compare_with') ?: null, $this->int($_GET['unit_id'] ?? null));
            });
    }

    public function entries(): void
    {
        $detailed = filter_var($_GET['detailed'] ?? false, FILTER_VALIDATE_BOOL);
        $this->run($detailed ? 'entries-detailed' : 'entries', ['erp.entries.view'], fn (Reports $r, User $u, ?int $c): array => $r->entries($u, $c,
            $this->text('from'), $this->text('to'), ['nature' => $this->text('nature'), 'situation' => $this->text('situation')], $detailed));
    }

    public function bankCharges(): void
    {
        $this->run('bank-charges', ['erp.bank_charges.view'], fn (Reports $r, User $u, ?int $c): array => $r->bankCharges($u, $c, $this->text('from'), $this->text('to'),
            $this->text('status') ?: null));
    }

    /** @param list<string> $permissions */
    private function run(string $kind, array $permissions, callable $report): void
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
        $condominium = $this->int($_GET['condominium_id'] ?? null);
        try {
            $data = $report(new Reports(), $user, $condominium);
            header('Cache-Control: no-store, private');
            if ($this->text('format') === 'csv') {
                $content = CollectionReports::csv(Reports::csvTable($kind, $data));
                Audit::record('export', 'erp_reports', 0, null, ['report' => $kind, 'condominium_id' => $condominium, 'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $kind . '-' . date('Y-m-d') . '.csv"');
                header('X-Content-Type-Options: nosniff');
                echo $content;
                return;
            }
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio ou conta não encontrado no seu escopo.']]);
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
