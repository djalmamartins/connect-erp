<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Source\Services\Erp\ListDocument;
use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\ReportExportLimit;
use Source\Services\Erp\SupplierSpend as Service;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Gastos por fornecedor (ADR 0190), somente leitura; ?format=csv baixa o mesmo relatório em CSV seguro:
 *  GET /api/v1/reports/supplier-spend?condominium_id=&from=AAAA-MM&to=AAAA-MM
 * Exige erp.entries.view. Fora do escopo = 404; sem condomínio ou competências inválidas = 422. CSV: até 30 por hora
 * (ADR 0087) e auditado.
 */
final class SupplierSpend
{
    public function show(): void
    {
        $user = Auth::user();
        if ($user === null) {
            JsonResponse::send(401, ['error' => ['code' => 'unauthenticated', 'message' => 'Autenticação necessária.']]);
            return;
        }
        if (!Access::can('erp.access', $user) || !Access::can('erp.entries.view', $user)) {
            JsonResponse::send(403, ['error' => ['code' => 'forbidden', 'message' => 'Sem permissão.']]);
            return;
        }
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        try {
            $data = (new Service())->build($user, $condominium === false || $condominium === null ? null : (int)$condominium, $_GET['from'] ?? null, $_GET['to'] ?? null);
            header('Cache-Control: no-store, private');
            if (($format = ListDocument::format($_GET['format'] ?? null)) !== null) {   // CSV, PDF ou Excel (ADR 0314)
                (new ReportExportLimit())->assertAllowed($user);
                $content = ListDocument::render($format, Service::csvTable($data), ListDocument::meta($_GET['title'] ?? null, 'Gastos por fornecedor', (int)$data['condominium_id']));
                Audit::record('export', 'erp_reports', 0, null, ['report' => 'supplier-spend', 'condominium_id' => $data['condominium_id'],
                    'from' => $data['from'], 'to' => $data['to'], 'format' => $format, 'bytes' => strlen($content)]);
                ListDocument::send($format, $content, ListDocument::rename('supplier-spend-' . $data['condominium_id'] . '-' . $data['from'] . '-' . $data['to'] . '.csv', $format));
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
