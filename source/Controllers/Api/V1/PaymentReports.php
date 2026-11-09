<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use DomainException;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\PaymentReports as Reports;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Relatórios de pagamentos, memória de cálculo e transferências (ADR 0089), somente leitura; ?format=csv
 * com o CSV seguro do ADR 0081 e o limite de 30 exportações por hora (ADR 0087):
 *  GET /api/v1/reports/payments?condominium_id=&from=&to=&nature=       AP-074 (erp.entries.view)
 *  GET /api/v1/reports/calculation?condominium_id=&unit_id=&as_of=      AP-075 (erp.entries.view e erp.agreements.view)
 *  GET /api/v1/reports/transfers?condominium_id=&from=&to=&active_only=  AP-076 (erp.entries.view e erp.bank_accounts.view)
 */
final class PaymentReports
{
    public function payments(): void
    {
        $this->report('payments', ['erp.entries.view'], fn (Reports $r, $u, ?int $c): array => $r->payments($u, $c, $this->text('from'), $this->text('to'),
            $this->text('nature') === '' ? 'despesa' : $this->text('nature')));
    }

    public function calculation(): void
    {
        $this->report('calculation', ['erp.entries.view', 'erp.agreements.view'], function (Reports $r, $u, ?int $c): array {
            if ($c === null) {
                throw new InvalidArgumentException('Escolha o condomínio: a memória de cálculo usa a regra de cobrança dele.');
            }
            return $r->calculation($u, $c, $this->int($_GET['unit_id'] ?? null), $this->text('as_of') === '' ? null : $this->text('as_of'));
        });
    }

    public function transfers(): void
    {
        $this->report('transfers', ['erp.entries.view', 'erp.bank_accounts.view'], fn (Reports $r, $u, ?int $c): array => $r->transfers($u, $c,
            $this->text('from'), $this->text('to'), $this->text('active_only') !== '1'));
    }

    /** @param list<string> $permissions */
    private function report(string $kind, array $permissions, callable $report): void
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
        } catch (CondominiumAccessDenied | RegistryNotFound) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        } catch (DomainException $exception) {
            JsonResponse::send(429, ['error' => ['code' => 'too_many_exports', 'message' => $exception->getMessage()]]);
        }
    }

    private function text(string $name): string
    {
        $value = $_GET[$name] ?? '';
        return is_string($value) ? trim($value) : '';
    }

    private function int(mixed $value): ?int
    {
        $int = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $int === false ? null : (int)$int;
    }
}
