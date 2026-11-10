<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Relatórios contábeis (ADR 0165), somente leitura e calculados dos lançamentos válidos (sem tabela própria):
 *
 * - ledger() — Livro Razão (AP-083): por conta do plano de contas, o saldo anterior, cada lançamento do
 *   período com débito ou crédito e o saldo acumulado, e o saldo final. Conta de despesa movimenta a
 *   débito e conta de receita a crédito; o saldo da conta cresce na sua natureza. O saldo inicial do plano
 *   (AP-161) com data até o início do período é a abertura da conta e substitui os lançamentos anteriores
 *   à data dele (ADR 0167); saldo com data depois do início fica de fora e aparece em openings_ignored.
 * - accountBalances() — Saldos de Contas (AP-073): saldo de cada conta bancária numa data (as_of) ou num
 *   período (saldo anterior, entradas, saídas e saldo final), pela mesma conciliação do AP-051
 *   (FinancialReports::statement), com saldo inicial e transferências.
 * - incomeStatement() — Demonstração do Resultado (AP-082): receitas menos despesas no período, somadas no
 *   plano de contas (analíticas nas sintéticas), com o resultado e a margem.
 *
 * Período em datas (AAAA-MM-DD, até 366 dias). basis=cash usa a data do pagamento; basis=competence usa as
 * competências (AAAA-MM) cobertas pelas datas. Escopo (ADR-002): um condomínio (fora = CondominiumAccessDenied,
 * 404) ou todos os do escopo. Lançamento cancelado ou excluído não entra.
 */
final class AccountingReports
{
    public const BASES = ['competence', 'cash'];
    public const MAX_DAYS = 366;
    public const MAX_LINES = 5000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array{0:string, 1:string} período validado (regra pura) */
    public static function period(mixed $from, mixed $to): array
    {
        $from = is_string($from) ? $from : '';
        $to = is_string($to) ? $to : '';
        $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $to);
        if ($start === false || $end === false || $start->format('Y-m-d') !== $from || $end->format('Y-m-d') !== $to || $from > $to) {
            throw new InvalidArgumentException('Informe o período (AAAA-MM-DD a AAAA-MM-DD).');
        }
        if ($start->diff($end)->days > self::MAX_DAYS) {
            throw new InvalidArgumentException('Período máximo de ' . self::MAX_DAYS . ' dias.');
        }
        return [$from, $to];
    }

    public static function basis(mixed $basis): string
    {
        $basis = is_string($basis) && $basis !== '' ? $basis : 'competence';
        if (!in_array($basis, self::BASES, true)) {
            throw new InvalidArgumentException('Base inválida: use competence ou cash.');
        }
        return $basis;
    }

    /**
     * Livro Razão (regra pura): agrupa os lançamentos por conta, na ordem recebida, com o saldo acumulado.
     *
     * @param array<string, int> $openings saldo anterior por "natureza|código"
     * @param list<array{code:string, name:string, nature:string, id:int|string, on_date:?string, competence:?string, description:string, cents:int|string}> $lines
     * @return list<array{code:string, name:string, nature:string, opening_cents:int, debit_cents:int, credit_cents:int, closing_cents:int, lines:list<array>}>
     */
    public static function ledgerAccounts(array $openings, array $lines, array $names = []): array
    {
        $accounts = [];
        foreach ($openings as $key => $cents) {
            [$nature, $code] = explode('|', $key, 2);
            $accounts[$key] = self::account($code, $names[$key] ?? $code, $nature, (int)$cents);
        }
        foreach ($lines as $line) {
            $key = $line['nature'] . '|' . $line['code'];
            $accounts[$key] ??= self::account((string)$line['code'], (string)$line['name'], (string)$line['nature'], 0);
            $accounts[$key]['name'] = (string)$line['name'];
            $cents = (int)$line['cents'];
            if (isset($line['side'])) {
                // Lançamento patrimonial (ADR 0169): o lado vem do lançamento e o saldo cresce na natureza da conta.
                $debit = $line['side'] === 'debit' ? $cents : 0;
                $credit = $line['side'] === 'credit' ? $cents : 0;
                $delta = PatrimonialEntries::signed((string)$line['nature'], (string)$line['side'], $cents);
            } else {
                $debit = $line['nature'] === 'despesa' ? $cents : 0;
                $credit = $line['nature'] === 'receita' ? $cents : 0;
                $delta = $cents;
            }
            $accounts[$key]['debit_cents'] += $debit;
            $accounts[$key]['credit_cents'] += $credit;
            $accounts[$key]['closing_cents'] += $delta;
            $accounts[$key]['lines'][] = ['entry_id' => (int)$line['id'], 'source' => isset($line['side']) ? 'patrimonial' : 'entry', 'on_date' => $line['on_date'],
                'competence' => $line['competence'], 'description' => (string)$line['description'], 'debit_cents' => $debit, 'credit_cents' => $credit,
                'balance_cents' => $accounts[$key]['closing_cents']];
        }
        $order = ['receita' => 0, 'despesa' => 1, 'asset' => 2, 'liability' => 3, 'equity' => 4];
        uasort($accounts, static fn (array $a, array $b): int => [$order[$a['nature']] ?? 5, self::sortable($a['code'])]
            <=> [$order[$b['nature']] ?? 5, self::sortable($b['code'])]);
        return array_values($accounts);
    }

    /**
     * Demonstração do Resultado (regra pura): soma nas sintéticas e calcula resultado e margem.
     *
     * @param list<array{code:string, name:string, nature:string, cents:int|string}> $rows
     * @return array{rows:list<array>, totals:array{revenue_cents:int, expense_cents:int, result_cents:int, margin_percent:?float}}
     */
    public static function income(array $rows, array $names = []): array
    {
        $rolled = FinancialReports::rollup(array_map(static fn (array $r): array => ['code' => (string)$r['code'], 'name' => (string)$r['name'],
            'nature' => (string)$r['nature'], 'planned_cents' => (int)$r['cents'], 'realized_cents' => 0, 'open_cents' => 0], $rows), $names);
        $out = array_map(static fn (array $r): array => ['code' => $r['code'], 'name' => $r['name'], 'nature' => $r['nature'], 'level' => $r['level'],
            'analytic' => $r['analytic'], 'amount_cents' => $r['planned_cents']], $rolled);
        $revenue = $expense = 0;
        foreach ($rows as $r) {
            $r['nature'] === 'receita' ? $revenue += (int)$r['cents'] : ($r['nature'] === 'despesa' ? $expense += (int)$r['cents'] : null);
        }
        $result = $revenue - $expense;
        return ['rows' => $out, 'totals' => ['revenue_cents' => $revenue, 'expense_cents' => $expense, 'result_cents' => $result,
            'margin_percent' => $revenue > 0 ? round($result * 100 / $revenue, 2) : null]];
    }

    public function ledger(User $user, ?int $condominiumId, mixed $from, mixed $to, mixed $basis = 'competence', mixed $accountCode = null): array
    {
        [$from, $to] = self::period($from, $to);
        $basis = self::basis($basis);
        $code = is_string($accountCode) && preg_match('/^\d+(\.\d+)*$/', trim($accountCode)) === 1 ? trim($accountCode) : null;
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to, 'basis' => $basis, 'account_code' => $code];
        if ($ids === []) {
            return $base + ['openings_ignored' => [], 'truncated' => false, 'accounts' => []];
        }
        $in = implode(',', $ids);
        [$before, $within, $params, $date] = $this->periodSql($basis, $from, $to);
        $codeSql = $code !== null ? ' AND (a.code = :code OR a.code LIKE :code_prefix)' : '';
        $codeParams = $code !== null ? ['code' => $code, 'code_prefix' => $code . '.%'] : [];
        $openings = $this->pdo->prepare("SELECT a.code, a.name, e.nature, SUM(e.amount_cents) AS cents FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND {$before}{$codeSql} GROUP BY a.code, a.name, e.nature");
        $openings->execute(['f' => $params['f']] + $codeParams);
        $opening = [];
        $names = [];
        foreach ($openings->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['nature'] . '|' . $row['code'];
            $opening[$key] = ($opening[$key] ?? 0) + (int)$row['cents'];
            $names[$key] = (string)$row['name'];
        }
        // Saldo inicial do plano (AP-161, ADR 0167): com data até o início do período, vira a abertura da conta
        // e substitui os lançamentos anteriores à data dele; os lançamentos depois da data somam normalmente.
        $replaced = $basis === 'cash' ? 'e.paid_on < o.opened_on' : 'e.competence < SUBSTR(o.opened_on, 1, 7)';
        $initial = $this->pdo->prepare("SELECT a.code, a.name, a.nature, SUM(o.amount_cents) AS cents FROM erp_chart_account_openings o
            JOIN erp_chart_accounts a ON a.id = o.chart_account_id WHERE o.condominium_id IN ({$in}) AND o.opened_on <= :fd{$codeSql} GROUP BY a.code, a.name, a.nature");
        $initial->execute(['fd' => $from] + $codeParams);
        foreach ($initial->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['nature'] . '|' . $row['code'];
            $opening[$key] = ($opening[$key] ?? 0) + (int)$row['cents'];
            $names[$key] = (string)$row['name'];
        }
        $replacedBefore = $this->pdo->prepare("SELECT a.code, e.nature, SUM(e.amount_cents) AS cents FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            JOIN erp_chart_account_openings o ON o.condominium_id = e.condominium_id AND o.chart_account_id = e.chart_account_id AND o.opened_on <= :fd
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND {$before} AND {$replaced}{$codeSql} GROUP BY a.code, e.nature");
        $replacedBefore->execute(['f' => $params['f'], 'fd' => $from] + $codeParams);
        foreach ($replacedBefore->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $key = $row['nature'] . '|' . $row['code'];
            $opening[$key] = ($opening[$key] ?? 0) - (int)$row['cents'];
        }
        $later = $this->pdo->prepare("SELECT DISTINCT a.code FROM erp_chart_account_openings o JOIN erp_chart_accounts a ON a.id = o.chart_account_id
            WHERE o.condominium_id IN ({$in}) AND o.opened_on > :fd{$codeSql} ORDER BY a.code");
        $later->execute(['fd' => $from] + $codeParams);
        $base['openings_ignored'] = array_map('strval', $later->fetchAll(PDO::FETCH_COLUMN));
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature, e.id, {$date} AS on_date, e.competence, e.description, e.amount_cents AS cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND {$within}{$codeSql}
            ORDER BY a.code, {$date}, e.id LIMIT " . (self::MAX_LINES + 1));
        $stmt->execute($params + $codeParams);
        $lines = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // Lançamentos patrimoniais (ADR 0169): cada lado (débito e crédito) é uma linha da sua conta, pela data do
        // lançamento nas duas bases. O que vem antes do saldo inicial (AP-161) da conta fica de fora, como nos demais.
        foreach (['debit' => 'debit_account_id', 'credit' => 'credit_account_id'] as $side => $column) {
            $join = "FROM erp_patrimonial_entries m JOIN erp_chart_accounts a ON a.id = m.{$column}
                LEFT JOIN erp_chart_account_openings o ON o.condominium_id = m.condominium_id AND o.chart_account_id = a.id AND o.opened_on <= :fo
                WHERE m.condominium_id IN ({$in}) AND (o.chart_account_id IS NULL OR m.entry_date >= o.opened_on){$codeSql}";
            $prior = $this->pdo->prepare("SELECT a.code, a.name, a.nature, SUM(m.amount_cents) AS cents {$join} AND m.entry_date < :fd GROUP BY a.code, a.name, a.nature");
            $prior->execute(['fo' => $from, 'fd' => $from] + $codeParams);
            foreach ($prior->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $key = $row['nature'] . '|' . $row['code'];
                $opening[$key] = ($opening[$key] ?? 0) + PatrimonialEntries::signed((string)$row['nature'], $side, (int)$row['cents']);
                $names[$key] = (string)$row['name'];
            }
            $period = $this->pdo->prepare("SELECT a.code, a.name, a.nature, m.id, m.entry_date AS on_date, NULL AS competence, m.description, m.amount_cents AS cents
                {$join} AND m.entry_date BETWEEN :fd AND :td ORDER BY a.code, m.entry_date, m.id LIMIT " . (self::MAX_LINES + 1));
            $period->execute(['fo' => $from, 'fd' => $from, 'td' => $to] + $codeParams);
            foreach ($period->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $lines[] = $row + ['side' => $side];
            }
        }
        usort($lines, static fn (array $a, array $b): int => [self::sortable((string)$a['code']), (string)$a['on_date'], isset($a['side']) ? 1 : 0, (int)$a['id']]
            <=> [self::sortable((string)$b['code']), (string)$b['on_date'], isset($b['side']) ? 1 : 0, (int)$b['id']]);
        $truncated = count($lines) > self::MAX_LINES;
        return $base + ['truncated' => $truncated, 'accounts' => self::ledgerAccounts($opening, array_slice($lines, 0, self::MAX_LINES), $names)];
    }

    /** Saldos das contas bancárias numa data (as_of) ou num período (from/to). */
    public function accountBalances(User $user, ?int $condominiumId, mixed $asOf = null, mixed $from = null, mixed $to = null): array
    {
        if (is_string($asOf) && $asOf !== '') {
            [$from, $to] = self::period($asOf, $asOf);
        } else {
            [$from, $to] = self::period($from, $to);
            $asOf = null;
        }
        $this->ids($user, $condominiumId);   // 404 antes de consultar
        $statement = (new FinancialReports($this->pdo, $this->scope))->statement($user, $condominiumId, $from, $to);
        $banks = array_map(static fn (array $b): array => ['bank_account_id' => $b['id'], 'condominium_id' => $b['condominium_id'], 'condominium_name' => $b['condominium_name'],
            'name' => $b['name'], 'opening_cents' => $b['opening_cents'], 'received_cents' => $b['received_cents'], 'paid_cents' => $b['paid_cents'],
            'closing_cents' => $b['closing_cents']], $statement['banks']);
        return ['condominium_id' => $condominiumId, 'as_of' => $asOf, 'from' => $from, 'to' => $to, 'truncated' => false, 'accounts' => $banks,
            'totals' => ['opening_cents' => array_sum(array_column($banks, 'opening_cents')), 'received_cents' => array_sum(array_column($banks, 'received_cents')),
                'paid_cents' => array_sum(array_column($banks, 'paid_cents')), 'closing_cents' => array_sum(array_column($banks, 'closing_cents'))]];
    }

    /**
     * Razão das contas bancárias (7.1 Disponível, ADR 0170): por conta corrente, poupança ou aplicação, o saldo
     * anterior (da conciliação, accountBalances), cada recebimento e pagamento com a conta bancária e cada
     * transferência nos dois lados, com o saldo acumulado. A transferência sai de uma conta e entra na outra: no
     * total do Disponível soma zero e não passa pela DRE.
     */
    public function bankLedger(User $user, ?int $condominiumId, mixed $from, mixed $to): array
    {
        [$from, $to] = self::period($from, $to);
        $balances = $this->accountBalances($user, $condominiumId, null, $from, $to);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to];
        $banks = [];
        foreach ($balances['accounts'] as $b) {
            $banks[(int)$b['bank_account_id']] = ['bank_account_id' => (int)$b['bank_account_id'], 'condominium_id' => (int)$b['condominium_id'], 'name' => (string)$b['name'],
                'kind' => 'corrente', 'opening_cents' => (int)$b['opening_cents'], 'debit_cents' => 0, 'credit_cents' => 0, 'closing_cents' => (int)$b['opening_cents'], 'lines' => []];
        }
        if ($banks === []) {
            return $base + ['truncated' => false, 'accounts' => [], 'totals' => ['opening_cents' => 0, 'debit_cents' => 0, 'credit_cents' => 0, 'closing_cents' => 0]];
        }
        foreach ((new Investments($this->pdo, $this->scope))->kinds(array_keys($banks)) as $id => $k) {
            $banks[$id]['kind'] = $k['kind'];
        }
        $in = implode(',', array_keys($banks));
        $lines = [];
        $entries = $this->pdo->prepare("SELECT e.id, e.bank_account_id AS bank, e.paid_on AS on_date, e.description, e.nature, e.amount_cents AS cents
            FROM erp_entries e JOIN erp_bank_accounts b ON b.id = e.bank_account_id
            WHERE e.bank_account_id IN ({$in}) AND e.condominium_id = b.condominium_id AND e.deleted_at IS NULL AND e.status = 'active'
              AND e.paid_on BETWEEN :f AND :t LIMIT " . (self::MAX_LINES + 1));
        $entries->execute(['f' => $from, 't' => $to]);
        foreach ($entries->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = ['source' => 'entry', 'bank' => (int)$r['bank'], 'id' => (int)$r['id'], 'on_date' => (string)$r['on_date'], 'description' => (string)$r['description'],
                'side' => $r['nature'] === 'receita' ? 'debit' : 'credit', 'cents' => (int)$r['cents'], 'counterpart_bank_account_id' => null];
        }
        foreach (['from_bank_account_id' => 'credit', 'to_bank_account_id' => 'debit'] as $column => $side) {
            $other = $column === 'from_bank_account_id' ? 'to_bank_account_id' : 'from_bank_account_id';
            $transfers = $this->pdo->prepare("SELECT t.id, t.{$column} AS bank, t.{$other} AS other, t.transferred_on AS on_date, t.description, t.amount_cents AS cents
                FROM erp_bank_transfers t WHERE t.{$column} IN ({$in}) AND t.status = 'active' AND t.transferred_on BETWEEN :f AND :t LIMIT " . (self::MAX_LINES + 1));
            $transfers->execute(['f' => $from, 't' => $to]);
            foreach ($transfers->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $lines[] = ['source' => 'transfer', 'bank' => (int)$r['bank'], 'id' => (int)$r['id'], 'on_date' => (string)$r['on_date'], 'description' => (string)$r['description'],
                    'side' => $side, 'cents' => (int)$r['cents'], 'counterpart_bank_account_id' => (int)$r['other']];
            }
        }
        usort($lines, static fn (array $a, array $b): int => [$a['bank'], $a['on_date'], $a['source'], $a['id'], $a['side']] <=> [$b['bank'], $b['on_date'], $b['source'], $b['id'], $b['side']]);
        $truncated = count($lines) > self::MAX_LINES;
        foreach (array_slice($lines, 0, self::MAX_LINES) as $line) {
            $bank = &$banks[$line['bank']];
            $debit = $line['side'] === 'debit' ? $line['cents'] : 0;
            $credit = $line['side'] === 'credit' ? $line['cents'] : 0;
            $bank['debit_cents'] += $debit;
            $bank['credit_cents'] += $credit;
            $bank['closing_cents'] += $debit - $credit;
            $bank['lines'][] = ['source' => $line['source'], 'id' => $line['id'], 'on_date' => $line['on_date'], 'description' => $line['description'],
                'debit_cents' => $debit, 'credit_cents' => $credit, 'balance_cents' => $bank['closing_cents'], 'counterpart_bank_account_id' => $line['counterpart_bank_account_id']];
            unset($bank);
        }
        $accounts = array_values($banks);
        return $base + ['truncated' => $truncated, 'accounts' => $accounts, 'totals' => ['opening_cents' => array_sum(array_column($accounts, 'opening_cents')),
            'debit_cents' => array_sum(array_column($accounts, 'debit_cents')), 'credit_cents' => array_sum(array_column($accounts, 'credit_cents')),
            'closing_cents' => array_sum(array_column($accounts, 'closing_cents'))]];
    }

    public function incomeStatement(User $user, ?int $condominiumId, mixed $from, mixed $to, mixed $basis = 'competence'): array
    {
        [$from, $to] = self::period($from, $to);
        $basis = self::basis($basis);
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to, 'basis' => $basis];
        if ($ids === []) {
            return $base + ['truncated' => false] + self::income([]);
        }
        $in = implode(',', $ids);
        [, $within, $params] = $this->periodSql($basis, $from, $to);
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature, SUM(e.amount_cents) AS cents FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND {$within}
            GROUP BY a.code, a.name, e.nature ORDER BY a.code LIMIT " . (self::MAX_LINES + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $base + ['truncated' => count($rows) > self::MAX_LINES] + self::income(array_slice($rows, 0, self::MAX_LINES), $this->syntheticNames($ids));
    }

    /**
     * CSV de cada relatório (regra pura), com as células de texto saneadas por CollectionReports::csvCell.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $date = static fn (mixed $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : '';
        $cell = static fn (mixed $v): string => CollectionReports::csvCell((string)$v);
        $rows = [];
        if ($kind === 'ledger') {
            foreach ($report['accounts'] as $a) {
                $rows[] = [$cell($a['code']), $cell($a['name']), '', 'Saldo anterior', '', '', $money($a['opening_cents'])];
                foreach ($a['lines'] as $l) {
                    $rows[] = [$cell($a['code']), $cell($a['name']), $date($l['on_date']), $cell($l['description']), $money($l['debit_cents']), $money($l['credit_cents']), $money($l['balance_cents'])];
                }
                $rows[] = [$cell($a['code']), $cell($a['name']), '', 'Saldo final', $money($a['debit_cents']), $money($a['credit_cents']), $money($a['closing_cents'])];
            }
            return ['header' => ['Código', 'Conta', 'Data', 'Histórico', 'Débito', 'Crédito', 'Saldo'], 'rows' => $rows];
        }
        if ($kind === 'account-balances') {
            foreach ($report['accounts'] as $a) {
                $rows[] = [$cell($a['condominium_name']), $cell($a['name']), $money($a['opening_cents']), $money($a['received_cents']), $money($a['paid_cents']), $money($a['closing_cents'])];
            }
            $t = $report['totals'];
            $rows[] = ['', 'Total', $money($t['opening_cents']), $money($t['received_cents']), $money($t['paid_cents']), $money($t['closing_cents'])];
            return ['header' => ['Condomínio', 'Conta bancária', 'Saldo anterior', 'Entradas', 'Saídas', 'Saldo final'], 'rows' => $rows];
        }
        foreach ($report['rows'] as $r) {
            $rows[] = [$cell($r['code']), $cell(str_repeat('  ', (int)$r['level'] - 1) . $r['name']), $r['nature'] === 'receita' ? 'Receita' : 'Despesa', $money($r['amount_cents'])];
        }
        $t = $report['totals'];
        foreach ([['Total de receitas', $t['revenue_cents']], ['Total de despesas', $t['expense_cents']], ['Resultado', $t['result_cents']]] as [$label, $value]) {
            $rows[] = ['', $label, '', $money($value)];
        }
        return ['header' => ['Código', 'Conta', 'Natureza', 'Valor'], 'rows' => $rows];
    }

    /** @return array{0:string, 1:string, 2:array<string,string>, 3:string} filtro de antes do período, do período, parâmetros e a coluna da data */
    private function periodSql(string $basis, string $from, string $to): array
    {
        if ($basis === 'cash') {
            return ['e.paid_on IS NOT NULL AND e.paid_on < :f', 'e.paid_on IS NOT NULL AND e.paid_on BETWEEN :f AND :t', ['f' => $from, 't' => $to], 'e.paid_on'];
        }
        return ['e.competence < :f', 'e.competence BETWEEN :f AND :t', ['f' => substr($from, 0, 7), 't' => substr($to, 0, 7)], 'e.due_on'];
    }

    private static function account(string $code, string $name, string $nature, int $opening): array
    {
        return ['code' => $code, 'name' => $name, 'nature' => $nature, 'opening_cents' => $opening, 'debit_cents' => 0, 'credit_cents' => 0,
            'closing_cents' => $opening, 'lines' => []];
    }

    private static function sortable(string $code): string
    {
        return implode('.', array_map(static fn (string $p): string => str_pad($p, 6, '0', STR_PAD_LEFT), explode('.', $code)));
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

    /** @param list<int> $ids @return array<string, string> */
    private function syntheticNames(array $ids): array
    {
        $in = implode(',', $ids);
        $rows = $this->pdo->query("SELECT code, name, condominium_id FROM erp_chart_accounts WHERE deleted_at IS NULL AND kind = 'sintetica'
            AND (condominium_id IS NULL OR condominium_id IN ({$in}))")->fetchAll(PDO::FETCH_ASSOC);
        usort($rows, static fn (array $a, array $b): int => ($a['condominium_id'] === null) <=> ($b['condominium_id'] === null));
        $names = [];
        foreach ($rows as $row) {
            $names[(string)$row['code']] ??= (string)$row['name'];
        }
        return $names;
    }
}
