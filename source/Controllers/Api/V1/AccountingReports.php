<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\AccountingReports as Reports;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatórios contábeis (ADR 0165), somente leitura; ?format=csv baixa o mesmo relatório em CSV seguro:
 *  GET /api/v1/reports/ledger?condominium_id=&from=&to=&basis=&account_code=     Livro Razão (AP-083)
 *  GET /api/v1/reports/account-balances?condominium_id=&as_of=  ou  &from=&to=    Saldos de Contas (AP-073)
 *  GET /api/v1/reports/income-statement?condominium_id=&from=&to=&basis=          Demonstração do Resultado (AP-082)
 * condominium_id vazio = todos do escopo; fora do escopo = 404; parâmetro inválido = 422. Exige erp.entries.view e
 * erp.chart.view, como os outros relatórios financeiros (ADR 0037). CSV: até 30 por hora (ADR 0087) e auditado.
 */
final class AccountingReports
{
    public function ledger(): void
    {
        $this->run('ledger', fn (Reports $r, $user, ?int $c): array => $r->ledger($user, $c, $_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['basis'] ?? 'competence', $_GET['account_code'] ?? null));
    }

    public function accountBalances(): void
    {
        $this->run('account-balances', fn (Reports $r, $user, ?int $c): array => $r->accountBalances($user, $c, $_GET['as_of'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null));
    }

    public function incomeStatement(): void
    {
        $this->run('income-statement', fn (Reports $r, $user, ?int $c): array => $r->incomeStatement($user, $c, $_GET['from'] ?? null, $_GET['to'] ?? null, $_GET['basis'] ?? 'competence'));
    }

    private function run(string $kind, callable $report): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user) || !Access::can('erp.chart.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $condominium = $condominium === false || $condominium === null ? null : (int)$condominium;
        try {
            $data = $report(new Reports(), $user, $condominium);
            header('Cache-Control: no-store, private');
            if (($_GET['format'] ?? '') === 'csv') {
                (new ReportExportLimit())->assertAllowed($user);
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
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
        }
    }
}
