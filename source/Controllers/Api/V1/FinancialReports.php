<?php

declare(strict_types=1);

namespace Source\Controllers\Api\V1;

use Connect\Http\JsonResponse;
use InvalidArgumentException;
use Source\Models\Auth;
use Source\Services\CondominiumAccessDenied;
use Source\Services\Erp\FinancialReports as Reports;
use Source\Support\Access;

/**
 * Relatórios financeiros (ADR 0036), somente leitura:
 *  GET /api/v1/reports/trial-balance?condominium_id=&from=AAAA-MM&to=AAAA-MM    balancete de verificação
 *  GET /api/v1/reports/statement?condominium_id=&from=AAAA-MM-DD&to=AAAA-MM-DD  prestação de contas por caixa
 * condominium_id vazio = todos os condomínios do escopo. Exige erp.entries.view e erp.chart.view.
 */
final class FinancialReports
{
    public function trialBalance(): void
    {
        $this->run(static fn (Reports $r, $user, ?int $c, string $f, string $t): array => $r->trialBalance($user, $c, $f, $t));
    }

    public function statement(): void
    {
        $this->run(static fn (Reports $r, $user, ?int $c, string $f, string $t): array => $r->statement($user, $c, $f, $t));
    }

    private function run(callable $report): void
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
            $data = $report(new Reports(), $user, $condominium === false || $condominium === null ? null : (int)$condominium,
                is_string($_GET['from'] ?? null) ? $_GET['from'] : '', is_string($_GET['to'] ?? null) ? $_GET['to'] : '');
            header('Cache-Control: no-store, private');
            JsonResponse::send(200, ['data' => $data]);
        } catch (InvalidArgumentException $exception) {
            JsonResponse::send(422, ['error' => ['code' => 'validation_failed', 'message' => $exception->getMessage()]]);
        } catch (CondominiumAccessDenied) {
            JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Condomínio não encontrado no seu escopo.']]);
        }
    }
}
