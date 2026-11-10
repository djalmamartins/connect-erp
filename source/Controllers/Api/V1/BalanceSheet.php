<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\BalanceSheet as Service;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Balanço Patrimonial (AP-081, ADR 0167), somente leitura; ?format=csv baixa o mesmo relatório em CSV seguro:
 *  GET /api/v1/reports/balance-sheet?condominium_id=&date=AAAA-MM-DD
 * condominium_id obrigatório e do escopo (fora = 404); data inválida ou sem condomínio = 422. Exige
 * erp.entries.view e erp.chart.view, como os outros relatórios contábeis (ADR 0165). CSV: até 30 por hora
 * (ADR 0087) e auditado.
 */
final class BalanceSheet
{
    public function show(): void
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
            $data = (new Service())->build($user, $condominium, $_GET['date'] ?? date('Y-m-d'));
            header('Cache-Control: no-store, private');
            if (($_GET['format'] ?? '') === 'csv') {
                (new ReportExportLimit())->assertAllowed($user);
                $content = CollectionReports::csv(Service::csvTable($data));
                Audit::record('export', 'erp_reports', 0, null, ['report' => 'balance-sheet', 'condominium_id' => $condominium, 'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="balance-sheet-' . $data['date'] . '.csv"');
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
