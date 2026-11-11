<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ReportExportLimit;
use Source\Services\Erp\UnitStatement as Service;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Extrato da unidade (ADR 0184), somente leitura; ?format=csv baixa o mesmo extrato em CSV seguro:
 *  GET /api/v1/reports/unit-statement?unit_id=&from=AAAA-MM-DD&to=AAAA-MM-DD
 * Unidade fora do escopo ou inexistente = 404; período inválido ou sem unidade = 422. Exige erp.entries.view, como os
 * relatórios de cobrança. CSV: até 30 por hora (ADR 0087) e auditado.
 */
final class UnitStatement
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
        try {
            $data = (new Service())->build($user, $_GET['unit_id'] ?? null, $_GET['from'] ?? null, $_GET['to'] ?? null);
            header('Cache-Control: no-store, private');
            if (($_GET['format'] ?? '') === 'csv') {
                (new ReportExportLimit())->assertAllowed($user);
                $content = CollectionReports::csv(Service::csvTable($data));
                Audit::record('export', 'erp_reports', 0, null, ['report' => 'unit-statement', 'unit_id' => $data['unit']['id'],
                    'condominium_id' => $data['unit']['condominium_id'], 'from' => $data['from'], 'to' => $data['to'], 'bytes' => strlen($content)]);
                header('Content-Type: text/csv; charset=utf-8');
                header('Content-Disposition: attachment; filename="unit-statement-' . $data['unit']['id'] . '-' . $data['from'] . '-' . $data['to'] . '.csv"');
                header('X-Content-Type-Options: nosniff');
                echo $content;
                return;
            }
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Unidade não encontrada no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
        }
    }
}
