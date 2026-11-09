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

    public function close(User $user, int $condominiumId, string $competence): array
    {
        $this->assertInput($user, $condominiumId, $competence);
        $this->pdo->beginTransaction();
        try {
            $before = $this->closingRow($condominiumId, $competence, true);
            if (($before['status'] ?? null) === 'closed') {
                throw new DomainException("A competência {$competence} já está fechada.");
            }
            $summary = self::summarize($this->entryRows($condominiumId, $competence));
            $values = [
                'condominium_id' => $condominiumId, 'competence' => $competence,
                'revenue_cents' => $summary['revenue_cents'], 'expense_cents' => $summary['expense_cents'],
                'open_revenue_cents' => $summary['open_revenue_cents'], 'open_expense_cents' => $summary['open_expense_cents'],
                'summary_json' => json_encode(['by_account' => $summary['by_account'], 'by_bank_account' => $summary['by_bank_account'], 'entries' => $summary['entries']], JSON_UNESCAPED_UNICODE),
                'closed_by' => (int)$user->id,
            ];
            $this->pdo->prepare("INSERT INTO erp_month_closings
                    (condominium_id, competence, status, revenue_cents, expense_cents, open_revenue_cents, open_expense_cents, summary_json, closed_by, closed_at)
                VALUES (:condominium_id, :competence, 'closed', :revenue_cents, :expense_cents, :open_revenue_cents, :open_expense_cents, :summary_json, :closed_by, NOW())
                ON DUPLICATE KEY UPDATE status = 'closed', revenue_cents = VALUES(revenue_cents), expense_cents = VALUES(expense_cents),
                    open_revenue_cents = VALUES(open_revenue_cents), open_expense_cents = VALUES(open_expense_cents),
                    summary_json = VALUES(summary_json), closed_by = VALUES(closed_by), closed_at = NOW()")
                ->execute($values);
            $after = $this->closingRow($condominiumId, $competence);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
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
        $before = $this->closingRow($condominiumId, $competence);
        if (($before['status'] ?? null) !== 'closed') {
            throw new DomainException("A competência {$competence} não está fechada.");
        }
        $this->pdo->prepare("UPDATE erp_month_closings SET status = 'reopened', reopened_by = :user, reopened_at = NOW(), reopen_reason = :reason
            WHERE id = :id AND status = 'closed'")->execute(['user' => (int)$user->id, 'reason' => $reason, 'id' => $before['id']]);
        $after = $this->closingRow($condominiumId, $competence);
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
    private function entryRows(int $condominiumId, string $competence): array
    {
        $stmt = $this->pdo->prepare("SELECT e.nature, e.amount_cents, e.paid_on, a.code AS account_code, a.name AS account_name,
                e.bank_account_id, b.name AS bank_name
            FROM erp_entries e
            LEFT JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id
            WHERE e.condominium_id = :c AND e.competence = :m AND e.deleted_at IS NULL AND e.status = 'active'
            ORDER BY e.id");
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
