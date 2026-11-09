<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CollectionReports as Reports;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatórios de cobrança (ADR 0081), somente leitura; ?format=csv baixa a mesma consulta em CSV:
 *  GET /api/v1/reports/agreements?condominium_id=&from=&to=&status=       acordos (AP-047)
 *  GET /api/v1/reports/agreement-fees?condominium_id=&from=&to=           taxas de acordos (AP-048)
 *  GET /api/v1/reports/delinquents?condominium_id=&as_of=&view=           inadimplentes (AP-057 a AP-061)
 *  GET /api/v1/reports/receipts?condominium_id=&from=&to=&group=          recebimentos (AP-071/072)
 * Acordos e inadimplentes exigem erp.agreements.view; a visão "resident" exige também
 * erp.residents.view; recebimentos exigem erp.entries.view. condominium_id vazio = todos do escopo.
 */
final class CollectionReports
{
    /** visão do relatório de inadimplentes → página do catálogo */
    private const VIEWS = ['general' => 'AP-057', 'unit' => 'AP-058', 'detailed' => 'AP-059', 'fee' => 'AP-060', 'resident' => 'AP-061'];

    public function agreements(): void
    {
        $this->run('agreements', ['erp.agreements.view'], fn (Reports $r, User $u, ?int $c): array => $r->agreements($u, $c, $this->text('from'), $this->text('to'),
            $this->text('status') ?: null));
    }

    public function agreementFees(): void
    {
        $this->run('agreement-fees', ['erp.agreements.view'], fn (Reports $r, User $u, ?int $c): array => $r->agreementFees($u, $c, $this->text('from'), $this->text('to')));
    }

    public function delinquents(): void
    {
        $view = $this->text('view') ?: 'unit';
        $permissions = $view === 'resident' ? ['erp.agreements.view', 'erp.residents.view'] : ['erp.agreements.view'];
        $this->run('delinquents-' . (isset(self::VIEWS[$view]) ? $view : 'unit'), $permissions,
            fn (Reports $r, User $u, ?int $c): array => $r->delinquents($u, $c, $this->text('as_of') ?: null, $view));
    }

    public function receipts(): void
    {
        $group = $this->text('group') ?: 'entry';
        $this->run('receipts-' . (in_array($group, Reports::RECEIPT_GROUPS, true) ? $group : 'entry'), ['erp.entries.view'],
            fn (Reports $r, User $u, ?int $c): array => $r->receipts($u, $c, $this->text('from'), $this->text('to'), $group));
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
        $condominium = filter_var($_GET['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $condominium = $condominium === false ? null : (int)$condominium;
        try {
            $data = $report(new Reports(), $user, $condominium);
            header('Cache-Control: no-store, private');
            if ($this->text('format') === 'csv') {
                $content = Reports::csv(Reports::csvTable($kind, $data));
                // Exportação de dados pessoais (visão por morador) fica na trilha de auditoria.
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
        }
    }

    private function text(string $name): string
    {
        return is_string($_GET[$name] ?? null) ? trim($_GET[$name]) : '';
    }
}
