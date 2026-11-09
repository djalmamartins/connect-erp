<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Relatórios financeiros (passo 6 do plano, ADR 0037), somente leitura e calculados dos lançamentos
 * válidos (sem tabela própria):
 *
 * - trialBalance(): balancete de verificação (AP-079) por conta do plano de contas num intervalo de
 *   competências: previsto (competência), realizado (pago) e em aberto. As contas analíticas sobem
 *   para as sintéticas pelo código (1.01.02 → 1.01 → 1).
 * - statement(): prestação de contas por regime de caixa num intervalo de datas, com a conciliação
 *   por conta bancária: saldo anterior + entradas − saídas = saldo final, conferido contra a soma dos
 *   lançamentos pagos. Diferença diferente de zero aparece no relatório, nunca é escondida.
 *
 * Escopo (ADR-002): um condomínio, ou todos os do escopo do usuário (null = contexto "Todos").
 */
final class FinancialReports
{
    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Soma as contas analíticas nas sintéticas pelo código (regra pura). Linhas de entrada: uma por
     * conta analítica com code, name, nature e os valores. $names dá o nome das sintéticas por código.
     *
     * @param list<array{code:string, name:string, nature:string, planned_cents:int, realized_cents:int, open_cents:int}> $rows
     * @param array<string, string> $names
     * @return list<array{code:string, name:string, nature:string, level:int, analytic:bool, planned_cents:int, realized_cents:int, open_cents:int}>
     */
    public static function rollup(array $rows, array $names = []): array
    {
        $tree = [];
        foreach ($rows as $row) {
            $parts = explode('.', $row['code']);
            for ($depth = 1; $depth <= count($parts); $depth++) {
                $code = implode('.', array_slice($parts, 0, $depth));
                $analytic = $depth === count($parts);
                $key = $row['nature'] . '|' . $code;
                $tree[$key] ??= ['code' => $code, 'name' => $analytic ? $row['name'] : ($names[$code] ?? $code), 'nature' => $row['nature'],
                    'level' => $depth, 'analytic' => $analytic, 'planned_cents' => 0, 'realized_cents' => 0, 'open_cents' => 0];
                foreach (['planned_cents', 'realized_cents', 'open_cents'] as $field) {
                    $tree[$key][$field] += (int)$row[$field];
                }
            }
        }
        uasort($tree, static function (array $a, array $b): int {
            $natures = ['receita' => 0, 'despesa' => 1];
            return [$natures[$a['nature']] ?? 2, self::sortableCode($a['code'])] <=> [$natures[$b['nature']] ?? 2, self::sortableCode($b['code'])];
        });
        return array_values($tree);
    }

    /** Movimento de uma conta bancária no período (regra pura): saldo anterior + entradas − saídas. */
    public static function bankMovement(int $openingCents, int $receivedCents, int $paidCents): array
    {
        return ['opening_cents' => $openingCents, 'received_cents' => $receivedCents, 'paid_cents' => $paidCents,
            'closing_cents' => $openingCents + $receivedCents - $paidCents];
    }

    /**
     * Conciliação da prestação de contas (regra pura): o caixa pelas contas do plano (recebido −
     * pago) tem de ser igual ao caixa pelas contas bancárias. A diferença (lançamento pago sem conta
     * bancária ou numa conta excluída) aparece no relatório; zero = conciliado.
     */
    public static function reconcile(int $receivedCents, int $paidCents, int $bankNetCents): array
    {
        $result = $receivedCents - $paidCents;
        return ['received_cents' => $receivedCents, 'paid_cents' => $paidCents, 'result_cents' => $result,
            'bank_net_cents' => $bankNetCents, 'difference_cents' => $result - $bankNetCents, 'reconciled' => $result === $bankNetCents];
    }

    /** Balancete de verificação por competência (AAAA-MM a AAAA-MM, no máximo 24 meses). */
    public function trialBalance(User $user, ?int $condominiumId, string $from, string $to): array
    {
        if (!RecurringEntries::isCompetence($from) || !RecurringEntries::isCompetence($to) || $from > $to) {
            throw new InvalidArgumentException('Informe o intervalo de competências (AAAA-MM a AAAA-MM).');
        }
        if (self::months($from, $to) > 24) {
            throw new InvalidArgumentException('Intervalo máximo de 24 competências.');
        }
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to];
        if ($ids === []) {
            return $base + ['rows' => [], 'totals' => self::totals([])];
        }
        $in = implode(',', $ids);
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature,
                SUM(e.amount_cents) AS planned_cents,
                SUM(CASE WHEN e.paid_on IS NOT NULL THEN e.amount_cents ELSE 0 END) AS realized_cents,
                SUM(CASE WHEN e.paid_on IS NULL THEN e.amount_cents ELSE 0 END) AS open_cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND e.competence BETWEEN :f AND :t
            GROUP BY a.code, a.name, e.nature");
        $stmt->execute(['f' => $from, 't' => $to]);
        $rows = array_map(static fn (array $r): array => ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'],
            'planned_cents' => (int)$r['planned_cents'], 'realized_cents' => (int)$r['realized_cents'], 'open_cents' => (int)$r['open_cents']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $rows = self::mergeSameCode($rows);
        return $base + ['rows' => self::rollup($rows, $this->syntheticNames($ids)), 'totals' => self::totals($rows)];
    }

    /** Prestação de contas por caixa (AAAA-MM-DD a AAAA-MM-DD, no máximo 366 dias), com conciliação bancária. */
    public function statement(User $user, ?int $condominiumId, string $from, string $to): array
    {
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $from > $to) {
            throw new InvalidArgumentException('Informe o período (AAAA-MM-DD a AAAA-MM-DD).');
        }
        if ($start->diff($end)->days > 366) {
            throw new InvalidArgumentException('Período máximo de 366 dias.');
        }
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to];
        if ($ids === []) {
            return $base + ['accounts' => [], 'banks' => [], 'totals' => self::reconcile(0, 0, 0)];
        }
        $in = implode(',', $ids);

        // Movimento do período por conta do plano (caixa: data do pagamento).
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature, SUM(e.amount_cents) AS total
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND e.paid_on BETWEEN :f AND :t
            GROUP BY a.code, a.name, e.nature ORDER BY e.nature DESC, a.code");
        $stmt->execute(['f' => $from, 't' => $to]);
        $accounts = array_map(static fn (array $r): array => ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'], 'total_cents' => (int)$r['total']], $stmt->fetchAll(PDO::FETCH_ASSOC));

        // Conciliação por conta bancária: saldo anterior (saldo inicial + movimento antes do período).
        $stmt = $this->pdo->prepare("SELECT b.id, b.condominium_id, c.name AS condominium_name, b.name, b.opening_balance_cents, b.opened_on,
                COALESCE(SUM(CASE WHEN e.paid_on < :f1 AND e.nature = 'receita' THEN e.amount_cents END), 0) AS before_in,
                COALESCE(SUM(CASE WHEN e.paid_on < :f2 AND e.nature = 'despesa' THEN e.amount_cents END), 0) AS before_out,
                COALESCE(SUM(CASE WHEN e.paid_on BETWEEN :f3 AND :t1 AND e.nature = 'receita' THEN e.amount_cents END), 0) AS period_in,
                COALESCE(SUM(CASE WHEN e.paid_on BETWEEN :f4 AND :t2 AND e.nature = 'despesa' THEN e.amount_cents END), 0) AS period_out,
                " . BankBalances::transferSql('in', 'b', ' AND t.transferred_on < :g1', false) . " AS before_tin,
                " . BankBalances::transferSql('out', 'b', ' AND t.transferred_on < :g2', false) . " AS before_tout,
                " . BankBalances::transferSql('in', 'b', ' AND t.transferred_on BETWEEN :g3 AND :h1', false) . " AS period_tin,
                " . BankBalances::transferSql('out', 'b', ' AND t.transferred_on BETWEEN :g4 AND :h2', false) . " AS period_tout
            FROM erp_bank_accounts b
            JOIN condominiums c ON c.id = b.condominium_id
            LEFT JOIN erp_entries e ON e.bank_account_id = b.id AND e.condominium_id = b.condominium_id AND e.deleted_at IS NULL AND e.status = 'active'
                AND e.paid_on IS NOT NULL AND e.paid_on <= :t0
            WHERE b.condominium_id IN ({$in}) AND b.deleted_at IS NULL
            GROUP BY b.id, b.condominium_id, c.name, b.name, b.opening_balance_cents, b.opened_on
            ORDER BY c.name, b.name");
        // Só lançamentos do condomínio da conta e até o fim do período (ADR 0102): os de depois não entram em
        // nenhuma soma e só pesavam na junção.
        $stmt->execute(['t0' => $to, 'f1' => $from, 'f2' => $from, 'f3' => $from, 't1' => $to, 'f4' => $from, 't2' => $to,
            'g1' => $from, 'g2' => $from, 'g3' => $from, 'h1' => $to, 'g4' => $from, 'h2' => $to]);
        $banks = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            // Saldo anterior = saldo inicial da conta + tudo o que foi pago antes do período. Transferências entre
            // contas (ADR 0057) entram como entrada numa conta e saída na outra: somam zero no condomínio.
            $banks[] = ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'], 'name' => (string)$r['name']]
                + self::bankMovement((int)$r['opening_balance_cents'] + (int)$r['before_in'] - (int)$r['before_out'] + (int)$r['before_tin'] - (int)$r['before_tout'],
                    (int)$r['period_in'] + (int)$r['period_tin'], (int)$r['period_out'] + (int)$r['period_tout'])
                + ['transfers_in_cents' => (int)$r['period_tin'], 'transfers_out_cents' => (int)$r['period_tout']];
        }

        $received = array_sum(array_map(static fn (array $a): int => $a['nature'] === 'receita' ? $a['total_cents'] : 0, $accounts));
        $paid = array_sum(array_map(static fn (array $a): int => $a['nature'] === 'despesa' ? $a['total_cents'] : 0, $accounts));
        $bankNet = array_sum(array_map(static fn (array $b): int => $b['received_cents'] - $b['paid_cents'], $banks));
        return $base + ['accounts' => $accounts, 'banks' => $banks, 'totals' => self::reconcile($received, $paid, $bankNet)];
    }

    /** @param list<array{nature:string, planned_cents:int, realized_cents:int, open_cents:int}> $rows */
    public static function totals(array $rows): array
    {
        $totals = ['planned_revenue_cents' => 0, 'planned_expense_cents' => 0, 'realized_revenue_cents' => 0, 'realized_expense_cents' => 0,
            'open_revenue_cents' => 0, 'open_expense_cents' => 0];
        foreach ($rows as $row) {
            $suffix = $row['nature'] === 'receita' ? 'revenue' : 'expense';
            $totals["planned_{$suffix}_cents"] += (int)$row['planned_cents'];
            $totals["realized_{$suffix}_cents"] += (int)$row['realized_cents'];
            $totals["open_{$suffix}_cents"] += (int)$row['open_cents'];
        }
        $totals['planned_result_cents'] = $totals['planned_revenue_cents'] - $totals['planned_expense_cents'];
        $totals['realized_result_cents'] = $totals['realized_revenue_cents'] - $totals['realized_expense_cents'];
        return $totals;
    }

    private static function months(string $from, string $to): int
    {
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        return ($ty - $fy) * 12 + ($tm - $fm) + 1;
    }

    /** "1.10" depois de "1.9": compara por número em cada nível. */
    private static function sortableCode(string $code): string
    {
        return implode('.', array_map(static fn (string $p): string => str_pad($p, 6, '0', STR_PAD_LEFT), explode('.', $code)));
    }

    /** Em "Todos", contas próprias de condomínios diferentes com o mesmo código somam numa linha. */
    private static function mergeSameCode(array $rows): array
    {
        $merged = [];
        foreach ($rows as $row) {
            $key = $row['nature'] . '|' . $row['code'];
            if (!isset($merged[$key])) {
                $merged[$key] = $row;
                continue;
            }
            foreach (['planned_cents', 'realized_cents', 'open_cents'] as $field) {
                $merged[$key][$field] += $row[$field];
            }
        }
        return array_values($merged);
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            return [$condominiumId];
        }
        return array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
    }

    /** @param list<int> $ids @return array<string, string> nome das contas sintéticas (padrão ou do condomínio) por código */
    private function syntheticNames(array $ids): array
    {
        $in = implode(',', $ids);
        $rows = $this->pdo->query("SELECT code, name FROM erp_chart_accounts WHERE deleted_at IS NULL AND kind = 'sintetica'
            AND (condominium_id IS NULL OR condominium_id IN ({$in})) ORDER BY condominium_id IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $names = [];
        foreach ($rows as $row) {
            $names[(string)$row['code']] ??= (string)$row['name'];   // a própria do condomínio vem antes do padrão
        }
        return $names;
    }

}
