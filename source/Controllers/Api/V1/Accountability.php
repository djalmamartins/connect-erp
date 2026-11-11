<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\Accountability as Service;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Prestação de contas mensal (AP-101, ADR 0166), somente leitura:
 *  GET /api/v1/reports/accountability?condominium_id=&month=AAAA-MM
 * condominium_id obrigatório e do escopo (fora = 404); mês inválido ou sem condomínio = 422. Exige
 * erp.entries.view e erp.chart.view, como os outros relatórios financeiros (ADR 0037). ?format=csv baixa o
 * mesmo pacote em CSV seguro (ADR 0178): até 30 por hora (ADR 0087) e auditado.
 */
final class Accountability
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
        try {
            $condominium = $condominium === false || $condominium === null ? null : (int)$condominium;
            $data = (new Service())->build($user, $condominium, $_GET['month'] ?? null);
            header('Cache-Control: no-store, private');
            if (($_GET['format'] ?? '') === 'csv') {
                (new ReportExportLimit())->assertAllowed($user);
                $content = CollectionReports::csv(Service::csvTable($data));
                Audit::record('export', 'erp_reports', 0, null, ['report' => 'accountability', 'condominium_id' => $condominium, 'month' => $data['competence'],
                    'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="accountability-' . $data['competence'] . '.csv"');
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
