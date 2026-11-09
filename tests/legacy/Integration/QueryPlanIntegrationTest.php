<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;

/**
 * Plano das consultas quentes (ADR 0064): dashboard, painéis, relatórios, saldos, conciliação e
 * filas. Confere pelo EXPLAIN que cada consulta tem um índice utilizável (possible_keys). Não
 * confere o índice escolhido (key), porque com poucas linhas o otimizador pode preferir ler a
 * tabela inteira. As consultas repetem o formato das usadas nos serviços citados.
 */
final class QueryPlanIntegrationTest extends TestCase
{
    /** @return array<string, array{string, string, string}> nome => [SQL, tabela, índice esperado] */
    public static function queries(): array
    {
        return [
            'prestação de contas por caixa (FinancialReports::statement)' => ["SELECT e.chart_account_id, SUM(e.amount_cents) FROM erp_entries e
                WHERE e.condominium_id IN (1, 2) AND e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on BETWEEN '2026-10-01' AND '2026-10-31'
                GROUP BY e.chart_account_id", 'e', 'idx_erp_entries_paid'],
            'balancete por competência (FinancialReports::trialBalance)' => ["SELECT e.chart_account_id, SUM(e.amount_cents) FROM erp_entries e
                WHERE e.condominium_id IN (1, 2) AND e.deleted_at IS NULL AND e.status = 'active' AND e.competence BETWEEN '2026-01' AND '2026-10'
                GROUP BY e.chart_account_id", 'e', 'idx_erp_entries_competence'],
            'saldo e conciliação por conta (BankBalances, Imports::systemBalance)' => ["SELECT SUM(e.amount_cents) FROM erp_entries e
                WHERE e.bank_account_id = 1 AND e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on IS NOT NULL AND e.paid_on <= '2026-10-31'",
                'e', 'idx_erp_entries_bank_paid'],
            'candidatos da conciliação do OFX (Imports::applyOfx)' => ["SELECT e.id FROM erp_entries e
                WHERE e.bank_account_id = 1 AND e.paid_on BETWEEN '2026-10-01' AND '2026-10-10' AND e.status = 'active' AND e.deleted_at IS NULL",
                'e', 'idx_erp_entries_bank_paid'],
            'a receber vencido do dashboard (OperationalDashboard::summary)' => ["SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries
                WHERE condominium_id IN (1, 2) AND deleted_at IS NULL AND status = 'active' AND nature = 'receita' AND paid_on IS NULL AND due_on < '2026-11-09'",
                'erp_entries', 'idx_erp_entries_condominium'],
            'contas a vencer da unidade (PanelSummaries::unit)' => ["SELECT id FROM erp_entries
                WHERE unit_id = 1 AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND paid_on IS NULL AND due_on >= '2026-11-09'",
                'erp_entries', 'idx_erp_entries_unit'],
            'manutenções atrasadas (OperationalDashboard::summary)' => ["SELECT COUNT(*) FROM erp_maintenances
                WHERE condominium_id IN (1, 2) AND deleted_at IS NULL AND status IN ('active','running') AND planned_on < '2026-11-09'",
                'erp_maintenances', 'idx_erp_maintenances_list'],
            'ocorrências abertas (OperationalDashboard::summary)' => ["SELECT COUNT(*) FROM erp_occurrences
                WHERE condominium_id IN (1, 2) AND deleted_at IS NULL AND status IN ('active','running')", 'erp_occurrences', 'idx_erp_occurrences_list'],
            'fila de avisos (NotificationDispatcher::dispatch)' => ["SELECT id FROM erp_notification_outbox
                WHERE status = 'pending' AND available_at <= NOW() ORDER BY available_at LIMIT 50", 'erp_notification_outbox', 'idx_erp_notification_outbox_queue'],
            'linhas pendentes do extrato (Imports::pendingLines)' => ["SELECT id FROM erp_bank_statement_lines
                WHERE condominium_id IN (1, 2) AND status = 'unmatched' ORDER BY posted_on", 'erp_bank_statement_lines', 'idx_erp_bank_statement_lines_review'],
            'divergências do retorno (BankReturns::divergences)' => ["SELECT id FROM erp_bank_events
                WHERE condominium_id IN (1, 2) AND outcome IN ('divergence','unknown_charge') AND resolved_at IS NULL", 'erp_bank_events', 'idx_erp_bank_events_review'],
        ];
    }

    public function testHotQueriesHaveAUsableIndex(): void
    {
        foreach (self::queries() as $name => [$sql, $table, $index]) {
            $plan = $this->pdo->query('EXPLAIN ' . $sql)->fetchAll(\PDO::FETCH_ASSOC);
            $row = array_values(array_filter($plan, static fn (array $r): bool => ($r['table'] ?? null) === $table))[0] ?? null;
            self::assertNotNull($row, "{$name}: a tabela {$table} não aparece no plano.");
            $keys = array_filter(explode(',', (string)($row['possible_keys'] ?? '')));
            self::assertContains($index, $keys, "{$name}: o índice {$index} não é utilizável (possible_keys: " . implode(', ', $keys) . ').');
        }
    }
}
