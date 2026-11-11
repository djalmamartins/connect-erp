<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Fechamento mensal (AP-030, ADR 0031) por condomínio e competência.
 *
 * - Resumo calculado dos lançamentos válidos da competência: receitas, despesas, quanto ainda
 *   está em aberto, resultado, total por conta do plano de contas e por conta bancária.
 * - Fechar grava a foto do resumo e trava os lançamentos daquela competência (RegistryService,
 *   'closing_lock'). Reabrir exige motivo. Fechar e reabrir são auditados.
 * - Escopo por condomínio (ADR-002): fora do escopo = CondominiumAccessDenied.
 * - Concorrência (ADR 0100): fechar grava a situação antes de somar, com a linha travada, e as
 *   gravações de lançamento conferem a competência com leitura travada na mesma transação.
 */
final class MonthClosing
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Regra pura do resumo a partir das linhas de lançamento.
     *
     * @param list<array{nature:string, amount_cents:int|string, paid_on:?string, account_code:?string, account_name:?string,
     *   bank_account_id:int|string|null, bank_name:?string}> $rows
     * @return array{revenue_cents:int, expense_cents:int, open_revenue_cents:int, open_expense_cents:int, result_cents:int,
     *   entries:int, by_account: list<array>, by_bank_account: list<array>}
     */
    public static function summarize(array $rows): array
    {
        $totals = ['revenue_cents' => 0, 'expense_cents' => 0, 'open_revenue_cents' => 0, 'open_expense_cents' => 0];
        $accounts = [];
        $banks = [];
        foreach ($rows as $row) {
            $amount = (int)$row['amount_cents'];
            $revenue = $row['nature'] === 'receita';
            $open = $row['paid_on'] === null || $row['paid_on'] === '';
            $totals[$revenue ? 'revenue_cents' : 'expense_cents'] += $amount;
            if ($open) {
                $totals[$revenue ? 'open_revenue_cents' : 'open_expense_cents'] += $amount;
            }
            $code = (string)($row['account_code'] ?? '');
            $accounts[$code] ??= ['code' => $code, 'name' => (string)($row['account_name'] ?? ''), 'nature' => $row['nature'], 'total_cents' => 0];
            $accounts[$code]['total_cents'] += $amount;
            if (!$open && $row['bank_account_id'] !== null) {
                $bank = (int)$row['bank_account_id'];
                $banks[$bank] ??= ['id' => $bank, 'name' => (string)($row['bank_name'] ?? ''), 'received_cents' => 0, 'paid_cents' => 0];
                $banks[$bank][$revenue ? 'received_cents' : 'paid_cents'] += $amount;
            }
        }
        ksort($accounts, SORT_STRING);
        ksort($banks);
        return $totals + [
            'result_cents' => $totals['revenue_cents'] - $totals['expense_cents'],
            'entries' => count($rows),
            'by_account' => array_values($accounts),
            'by_bank_account' => array_values($banks),
        ];
    }

    /** Resumo atual (calculado) + situação do fechamento. */
    public function status(User $user, int $condominiumId, string $competence): array
    {
        $this->assertInput($user, $condominiumId, $competence);
        $closing = $this->closingRow($condominiumId, $competence);
        return [
            'condominium_id' => $condominiumId, 'competence' => $competence,
            'status' => $closing['status'] ?? 'open',
            'closing' => $closing === null ? null : $this->presentClosing($closing),
            'summary' => self::summarize($this->entryRows($condominiumId, $competence)),
        ];
    }

    /**
     * Visão da competência para a tela AP-030, no contexto da barra superior (ADR 0032): um
     * condomínio, ou todos os do escopo do usuário quando o contexto é "Todos" (null).
     * Condomínio fora do escopo lança CondominiumAccessDenied (a tela responde 404).
     *
     * @return array{competence:string, condominium_id:?int, rows:list<array>, totals:array}
     */
    public function overview(User $user, ?int $condominiumId, string $competence): array
    {
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            $ids = [$condominiumId];
        } else {
            $ids = $this->scope->allowedIds($user);
        }
        $ids = array_values(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0));
        if ($ids === []) {
            return ['competence' => $competence, 'condominium_id' => $condominiumId, 'rows' => [], 'totals' => self::overviewTotals([])];
        }
        $in = implode(',', $ids);
        $stmt = $this->pdo->prepare("SELECT c.id AS condominium_id, c.name AS condominium_name,
                mc.status, mc.closed_at, mc.reopened_at, mc.reopen_reason,
                COALESCE(SUM(CASE WHEN e.nature = 'receita' THEN e.amount_cents END), 0) AS revenue_cents,
                COALESCE(SUM(CASE WHEN e.nature = 'despesa' THEN e.amount_cents END), 0) AS expense_cents,
                COALESCE(SUM(CASE WHEN e.nature = 'receita' AND e.paid_on IS NULL THEN e.amount_cents END), 0) AS open_revenue_cents,
                COALESCE(SUM(CASE WHEN e.nature = 'despesa' AND e.paid_on IS NULL THEN e.amount_cents END), 0) AS open_expense_cents,
                COUNT(e.id) AS entries
            FROM condominiums c
            LEFT JOIN erp_month_closings mc ON mc.condominium_id = c.id AND mc.competence = :m1
            LEFT JOIN erp_entries e ON e.condominium_id = c.id AND e.competence = :m2 AND e.deleted_at IS NULL AND e.status = 'active'
            WHERE c.id IN ({$in})
            GROUP BY c.id, c.name, mc.status, mc.closed_at, mc.reopened_at, mc.reopen_reason
            ORDER BY c.name, c.id");
        $stmt->execute(['m1' => $competence, 'm2' => $competence]);
        $rows = array_map(static fn (array $r): array => [
            'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
            'status' => $r['status'] !== null ? (string)$r['status'] : 'open',
            'closed_at' => $r['closed_at'], 'reopened_at' => $r['reopened_at'], 'reopen_reason' => $r['reopen_reason'],
            'revenue_cents' => (int)$r['revenue_cents'], 'expense_cents' => (int)$r['expense_cents'],
            'open_revenue_cents' => (int)$r['open_revenue_cents'], 'open_expense_cents' => (int)$r['open_expense_cents'],
            'result_cents' => (int)$r['revenue_cents'] - (int)$r['expense_cents'], 'entries' => (int)$r['entries'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['competence' => $competence, 'condominium_id' => $condominiumId, 'rows' => $rows, 'totals' => self::overviewTotals($rows)];
    }

    /**
     * Totais da visão (regra pura): somas e quantos condomínios estão fechados ou abertos
     * (reaberto conta como aberto, porque aceita lançamentos).
     *
     * @param list<array{status:string, revenue_cents:int, expense_cents:int, open_revenue_cents:int, open_expense_cents:int}> $rows
     */
    public static function overviewTotals(array $rows): array
    {
        $totals = ['condominiums' => count($rows), 'closed' => 0, 'open' => 0, 'revenue_cents' => 0, 'expense_cents' => 0,
            'open_revenue_cents' => 0, 'open_expense_cents' => 0, 'result_cents' => 0];
        foreach ($rows as $row) {
            $totals[$row['status'] === 'closed' ? 'closed' : 'open']++;
            foreach (['revenue_cents', 'expense_cents', 'open_revenue_cents', 'open_expense_cents'] as $key) {
                $totals[$key] += (int)$row[$key];
            }
        }
        $totals['result_cents'] = $totals['revenue_cents'] - $totals['expense_cents'];
        return $totals;
    }

    /** Competência pedida pela tela (?competencia=AAAA-MM); inválida ou ausente = mês atual. */
    public static function competenceOrCurrent(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        return RecurringEntries::isCompetence($value) ? $value : date('Y-m');
    }

    public function close(User $user, int $condominiumId, string $competence): array
    {
        $this->assertInput($user, $condominiumId, $competence);
        // Dentro de uma transação de fora (fechamento em lote, ADR 0205), grava nela: quem abriu confirma ou desfaz tudo.
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $before = $this->closingRow($condominiumId, $competence, true);
            if (($before['status'] ?? null) === 'closed') {
                throw new DomainException("A competência {$competence} já está fechada.");
            }
            // Primeiro grava a situação "fechada" (ADR 0100). Com a linha travada, quem grava lançamento confere
            // a competência com leitura travada e espera este fechamento; quem já gravou antes entra no resumo.
            $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status, closed_by, closed_at)
                VALUES (:c, :m, 'closed', :u, NOW())
                ON DUPLICATE KEY UPDATE status = 'closed', closed_by = VALUES(closed_by), closed_at = NOW()")
                ->execute(['c' => $condominiumId, 'm' => $competence, 'u' => (int)$user->id]);
            $summary = self::summarize($this->entryRows($condominiumId, $competence, true));
            $this->pdo->prepare('UPDATE erp_month_closings SET revenue_cents = :revenue_cents, expense_cents = :expense_cents,
                    open_revenue_cents = :open_revenue_cents, open_expense_cents = :open_expense_cents, summary_json = :summary_json
                WHERE condominium_id = :c AND competence = :m')
                ->execute([
                    'c' => $condominiumId, 'm' => $competence,
                    'revenue_cents' => $summary['revenue_cents'], 'expense_cents' => $summary['expense_cents'],
                    'open_revenue_cents' => $summary['open_revenue_cents'], 'open_expense_cents' => $summary['open_expense_cents'],
                    'summary_json' => json_encode(['by_account' => $summary['by_account'], 'by_bank_account' => $summary['by_bank_account'], 'entries' => $summary['entries']], JSON_UNESCAPED_UNICODE),
                ]);
            $after = $this->closingRow($condominiumId, $competence);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record($before === null ? 'create' : 'update', 'erp_month_closings', (int)$after['id'],
            $before === null ? null : ['status' => $before['status']],
            ['status' => 'closed', 'competence' => $competence, 'revenue_cents' => $summary['revenue_cents'], 'expense_cents' => $summary['expense_cents']]);
        return $this->presentClosing($after);
    }

    public function reopen(User $user, int $condominiumId, string $competence, string $reason): array
    {
        $this->assertInput($user, $condominiumId, $competence);
        $reason = trim((string)preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo da reabertura (5 a 255 caracteres).');
        }
        // Na mesma transação e com a linha travada (ADR 0100): duas reaberturas ao mesmo tempo não gravam
        // nem auditam duas vezes.
        $this->pdo->beginTransaction();
        try {
            $before = $this->closingRow($condominiumId, $competence, true);
            if (($before['status'] ?? null) !== 'closed') {
                throw new DomainException("A competência {$competence} não está fechada.");
            }
            $this->pdo->prepare("UPDATE erp_month_closings SET status = 'reopened', reopened_by = :user, reopened_at = NOW(), reopen_reason = :reason
                WHERE id = :id AND status = 'closed'")->execute(['user' => (int)$user->id, 'reason' => $reason, 'id' => $before['id']]);
            $after = $this->closingRow($condominiumId, $competence);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_month_closings', (int)$after['id'], ['status' => 'closed'], ['status' => 'reopened', 'reopen_reason' => $reason]);
        return $this->presentClosing($after);
    }

    private function assertInput(User $user, int $condominiumId, string $competence): void
    {
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $this->scope->assertAccess($user, $condominiumId);
    }

    /** @return list<array> */
    private function entryRows(int $condominiumId, string $competence, bool $lock = false): array
    {
        $stmt = $this->pdo->prepare("SELECT e.nature, e.amount_cents, e.paid_on, a.code AS account_code, a.name AS account_name,
                e.bank_account_id, b.name AS bank_name
            FROM erp_entries e
            LEFT JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id
            WHERE e.condominium_id = :c AND e.competence = :m AND e.deleted_at IS NULL AND e.status = 'active'
            ORDER BY e.id" . ($lock ? ' LOCK IN SHARE MODE' : ''));
        $stmt->execute(['c' => $condominiumId, 'm' => $competence]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function closingRow(int $condominiumId, string $competence, bool $forUpdate = false): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_month_closings WHERE condominium_id = :c AND competence = :m' . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute(['c' => $condominiumId, 'm' => $competence]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    private function presentClosing(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'competence' => (string)$row['competence'],
            'status' => (string)$row['status'],
            'revenue_cents' => (int)$row['revenue_cents'], 'expense_cents' => (int)$row['expense_cents'],
            'open_revenue_cents' => (int)$row['open_revenue_cents'], 'open_expense_cents' => (int)$row['open_expense_cents'],
            'summary' => $row['summary_json'] !== null ? json_decode((string)$row['summary_json'], true) : null,
            'closed_by' => $row['closed_by'] !== null ? (int)$row['closed_by'] : null, 'closed_at' => $row['closed_at'],
            'reopened_by' => $row['reopened_by'] !== null ? (int)$row['reopened_by'] : null, 'reopened_at' => $row['reopened_at'],
            'reopen_reason' => $row['reopen_reason'],
        ];
    }
}
