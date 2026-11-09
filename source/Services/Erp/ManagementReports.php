<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;

/**
 * Relatórios gerenciais (ADR 0082), somente leitura, calculados dos lançamentos, leituras, boletos e
 * transferências que já existem. Lançamento válido = status active e não excluído.
 *
 * - consumption() (AP-045/046): leituras por tipo, no geral ou por unidade.
 * - costCenters() (AP-049): previsto, realizado e em aberto por centro de custo.
 * - comparison() (AP-050): duas faixas de competência lado a lado por conta do plano.
 * - statement() (AP-052/053/054): demonstrativo por conta, analítico por lançamento e por situação.
 * - accountStatement() (AP-056): extrato da conta bancária com saldo corrido (lançamentos pagos e
 *   transferências, ADR 0057).
 * - apportionment() (AP-062 a AP-067): rateio das despesas da competência pela fração ideal
 *   (Invoices::apportion, sem perder centavo). Unidade sem fração impede o rateio e é listada.
 * - entries() (AP-068/069) e bankCharges() (AP-070): listagens com totais.
 *
 * Escopo por condomínio (ADR-002): um condomínio, ou todos os do usuário. CSV pelo mesmo
 * csvCell/csv do ADR 0081.
 */
final class ManagementReports
{
    public const MAX_MONTHS = 24;
    public const MAX_ROWS = 5000;
    public const SITUATIONS = ['paid' => 'Pago', 'open' => 'A vencer', 'overdue' => 'Vencido', 'cancelled' => 'Cancelado'];
    public const APPORTIONMENT_VIEWS = ['unit', 'compare', 'unit_account', 'unit_entries', 'entries', 'account'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de competências (regra pura): AAAA-MM, início até o fim, no máximo MAX_MONTHS meses. */
    public static function competences(string $from, string $to): array
    {
        if (!RecurringEntries::isCompetence($from) || !RecurringEntries::isCompetence($to)) {
            throw new InvalidArgumentException('Informe as competências (AAAA-MM).');
        }
        if ($to < $from) {
            throw new InvalidArgumentException('A competência final não pode ser anterior à inicial.');
        }
        [$fy, $fm] = array_map('intval', explode('-', $from));
        [$ty, $tm] = array_map('intval', explode('-', $to));
        if (($ty - $fy) * 12 + ($tm - $fm) + 1 > self::MAX_MONTHS) {
            throw new InvalidArgumentException(sprintf('No máximo %d competências.', self::MAX_MONTHS));
        }
        return [$from, $to];
    }

    /** Situação do lançamento numa data (regra pura). */
    public static function situation(array $entry, string $asOf): string
    {
        if (($entry['status'] ?? 'active') !== 'active') {
            return 'cancelled';
        }
        if (!empty($entry['paid_on'])) {
            return 'paid';
        }
        return (string)$entry['due_on'] < $asOf ? 'overdue' : 'open';
    }

    /** Variação entre dois valores (regra pura): diferença e percentual (null quando a base é zero). */
    public static function variation(int $a, int $b): array
    {
        return ['difference_cents' => $b - $a, 'percent' => $a === 0 ? null : round(($b - $a) * 100 / $a, 2)];
    }

    /**
     * Saldo corrido (regra pura): movimentos ordenados com amount_cents com sinal (+ entrada, − saída).
     *
     * @param list<array{amount_cents:int}> $moves
     * @return list<array> os mesmos movimentos com balance_cents
     */
    public static function runningBalance(int $opening, array $moves): array
    {
        $balance = $opening;
        foreach ($moves as &$move) {
            $balance += (int)$move['amount_cents'];
            $move['balance_cents'] = $balance;
        }
        unset($move);
        return $moves;
    }

    /**
     * Rateio (regra pura): cada despesa dividida pela fração ideal das unidades (Invoices::apportion,
     * centavo que sobra pelo maior resto). Devolve a parte de cada unidade em cada despesa.
     *
     * @param list<array{id:int, amount_cents:int}> $expenses
     * @param array<int, string|float> $fractions fração por unidade
     * @return array<int, array<int, int>> [id da despesa][id da unidade] => centavos
     */
    public static function shares(array $expenses, array $fractions): array
    {
        $out = [];
        foreach ($expenses as $expense) {
            $out[(int)$expense['id']] = (int)$expense['amount_cents'] > 0 ? Invoices::apportion((int)$expense['amount_cents'], $fractions) : array_map(static fn (): int => 0, $fractions);
        }
        return $out;
    }

    /** AP-045 (geral, por tipo) e AP-046 (por unidade e tipo): consumo e valor das leituras na faixa. */
    public function consumption(User $user, ?int $condominiumId, string $from, string $to, bool $byUnit): array
    {
        [$from, $to] = self::competences($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'by_unit' => $byUnit, 'rows' => []];
        if ($ids === []) {
            return $result;
        }
        $group = $byUnit ? 'r.condominium_id, m.unit_id, u.label, m.kind' : 'r.condominium_id, m.kind';
        $stmt = $this->pdo->prepare("SELECT r.condominium_id, c.name AS condominium_name, m.kind" . ($byUnit ? ', m.unit_id, u.label AS unit_label' : '') . ",
                COUNT(*) AS readings, SUM(r.consumption) AS consumption, SUM(r.amount_cents) AS amount_cents, SUM(r.entry_id IS NOT NULL) AS billed
            FROM erp_meter_readings r JOIN erp_meters m ON m.id = r.meter_id JOIN erp_units u ON u.id = m.unit_id JOIN condominiums c ON c.id = r.condominium_id
            WHERE r.condominium_id IN (" . implode(',', $ids) . ") AND r.competence BETWEEN :f AND :t
            GROUP BY {$group}, c.name ORDER BY c.name" . ($byUnit ? ', u.label' : '') . ', m.kind');
        $stmt->execute(['f' => $from, 't' => $to]);
        $result['rows'] = array_map(static fn (array $r): array => [
            'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'], 'kind' => (string)$r['kind'],
            'unit_id' => isset($r['unit_id']) ? (int)$r['unit_id'] : null, 'unit_label' => $r['unit_label'] ?? null,
            'readings' => (int)$r['readings'], 'consumption' => round((float)$r['consumption'], 3), 'amount_cents' => (int)$r['amount_cents'], 'billed' => (int)$r['billed'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $result;
    }

    /** AP-049: por centro de custo e natureza, previsto (competência), realizado (pago) e em aberto. */
    public function costCenters(User $user, ?int $condominiumId, string $from, string $to): array
    {
        [$from, $to] = self::competences($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'rows' => [], 'totals' => ['planned_cents' => 0, 'realized_cents' => 0, 'open_cents' => 0]];
        if ($ids === []) {
            return $result;
        }
        $stmt = $this->pdo->prepare("SELECT e.condominium_id, c.name AS condominium_name, e.nature, cc.code, COALESCE(cc.name, 'Sem centro de custo') AS cost_center,
                SUM(e.amount_cents) AS planned, SUM(CASE WHEN e.paid_on IS NOT NULL THEN e.amount_cents ELSE 0 END) AS realized
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id LEFT JOIN erp_cost_centers cc ON cc.id = e.cost_center_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN :f AND :t
            GROUP BY e.condominium_id, c.name, e.nature, cc.id, cc.code, cc.name ORDER BY c.name, e.nature DESC, cc.code IS NULL, cc.code");
        $stmt->execute(['f' => $from, 't' => $to]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $row = ['condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'], 'nature' => (string)$r['nature'],
                'code' => $r['code'], 'cost_center' => (string)$r['cost_center'], 'planned_cents' => (int)$r['planned'], 'realized_cents' => (int)$r['realized'],
                'open_cents' => (int)$r['planned'] - (int)$r['realized']];
            $result['rows'][] = $row;
            foreach (['planned_cents', 'realized_cents', 'open_cents'] as $field) {
                $result['totals'][$field] += $row[$field] * ($row['nature'] === 'receita' ? 1 : -1);
            }
        }
        return $result;
    }

    /** AP-050: duas faixas de competência (A e B) por conta do plano, com diferença e percentual. */
    public function comparison(User $user, ?int $condominiumId, string $fromA, string $toA, string $fromB, string $toB): array
    {
        [$fromA, $toA] = self::competences($fromA, $toA);
        [$fromB, $toB] = self::competences($fromB, $toB);
        $ids = $this->ids($user, $condominiumId);
        $result = ['a' => [$fromA, $toA], 'b' => [$fromB, $toB], 'rows' => []];
        if ($ids === []) {
            return $result;
        }
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature,
                SUM(CASE WHEN e.competence BETWEEN :fa AND :ta THEN e.amount_cents ELSE 0 END) AS amount_a,
                SUM(CASE WHEN e.competence BETWEEN :fb AND :tb THEN e.amount_cents ELSE 0 END) AS amount_b
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.status = 'active' AND e.deleted_at IS NULL
              AND (e.competence BETWEEN :fa2 AND :ta2 OR e.competence BETWEEN :fb2 AND :tb2)
            GROUP BY a.code, a.name, e.nature ORDER BY e.nature DESC, a.code");
        $stmt->execute(['fa' => $fromA, 'ta' => $toA, 'fb' => $fromB, 'tb' => $toB, 'fa2' => $fromA, 'ta2' => $toA, 'fb2' => $fromB, 'tb2' => $toB]);
        $result['rows'] = array_map(static fn (array $r): array => ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'],
            'a_cents' => (int)$r['amount_a'], 'b_cents' => (int)$r['amount_b']] + self::variation((int)$r['amount_a'], (int)$r['amount_b']), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $result;
    }

    /**
     * Demonstrativos na faixa de competência: 'accounts' (AP-052, por conta com previsto e realizado),
     * 'analytic' (AP-053, cada lançamento sob a conta) e 'situation' (AP-054, por natureza e situação).
     */
    public function statement(User $user, ?int $condominiumId, string $from, string $to, string $view): array
    {
        if (!in_array($view, ['accounts', 'analytic', 'situation'], true)) {
            throw new InvalidArgumentException('Visão inválida: accounts, analytic ou situation.');
        }
        [$from, $to] = self::competences($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'view' => $view, 'rows' => [], 'totals' => ['receita_cents' => 0, 'despesa_cents' => 0, 'result_cents' => 0]];
        if ($ids === []) {
            return $result;
        }
        $asOf = date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT e.id, e.condominium_id, c.name AS condominium_name, e.nature, e.description, e.competence, e.due_on, e.paid_on, e.status,
                e.amount_cents, a.code, a.name AS account, u.label AS unit_label
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id JOIN erp_chart_accounts a ON a.id = e.chart_account_id LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE e.condominium_id IN (" . implode(',', $ids) . ") AND e.deleted_at IS NULL AND e.competence BETWEEN :f AND :t
              " . ($view === 'situation' ? '' : "AND e.status = 'active'") . "
            ORDER BY e.nature DESC, a.code, e.due_on, e.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute(['f' => $from, 't' => $to]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($entries) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d lançamentos: reduza a faixa ou escolha um condomínio.', self::MAX_ROWS));
        }
        $rows = [];
        foreach ($entries as $e) {
            $situation = self::situation($e, $asOf);
            if ($situation !== 'cancelled') {
                $result['totals'][$e['nature'] . '_cents'] += (int)$e['amount_cents'];
            }
            if ($view === 'analytic') {
                $rows[] = ['code' => (string)$e['code'], 'account' => (string)$e['account'], 'nature' => (string)$e['nature'], 'entry_id' => (int)$e['id'],
                    'description' => (string)$e['description'], 'unit_label' => $e['unit_label'], 'competence' => (string)$e['competence'],
                    'due_on' => (string)$e['due_on'], 'paid_on' => $e['paid_on'], 'situation' => $situation, 'amount_cents' => (int)$e['amount_cents']];
                continue;
            }
            $key = $view === 'accounts' ? $e['code'] . '|' . $e['nature'] : $e['nature'] . '|' . $situation;
            $rows[$key] ??= $view === 'accounts'
                ? ['code' => (string)$e['code'], 'account' => (string)$e['account'], 'nature' => (string)$e['nature'], 'entries' => 0, 'planned_cents' => 0, 'realized_cents' => 0, 'open_cents' => 0]
                : ['nature' => (string)$e['nature'], 'situation' => $situation, 'situation_label' => self::SITUATIONS[$situation], 'entries' => 0, 'amount_cents' => 0];
            $rows[$key]['entries']++;
            if ($view === 'accounts') {
                $rows[$key]['planned_cents'] += (int)$e['amount_cents'];
                $rows[$key][$e['paid_on'] !== null ? 'realized_cents' : 'open_cents'] += (int)$e['amount_cents'];
            } else {
                $rows[$key]['amount_cents'] += (int)$e['amount_cents'];
            }
        }
        $result['rows'] = array_values($rows);
        $result['totals']['result_cents'] = $result['totals']['receita_cents'] - $result['totals']['despesa_cents'];
        return $result;
    }

    /** AP-056: extrato da conta bancária no período, com saldo anterior e saldo corrido. */
    public function accountStatement(User $user, int $bankAccountId, string $from, string $to): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        $stmt = $this->pdo->prepare('SELECT b.id, b.condominium_id, b.name, COALESCE(b.opening_balance_cents, 0) AS opening, b.opened_on FROM erp_bank_accounts b WHERE b.id = :id AND b.deleted_at IS NULL');
        $stmt->execute(['id' => $bankAccountId]);
        $bank = $stmt->fetch(PDO::FETCH_ASSOC);
        // Conta inexistente e conta de outro condomínio respondem igual (404), sem revelar que o id existe (ADR 0068).
        if (!is_array($bank)) {
            throw new CondominiumAccessDenied(0);
        }
        $this->scope->assertAccess($user, (int)$bank['condominium_id']);
        $since = $bank['opened_on'] !== null ? ' AND x.on_date >= :since' : '';
        // Movimentos: lançamentos pagos (receita +, despesa −) e transferências ativas (entra +, sai −).
        $moves = "SELECT * FROM (
            SELECT e.paid_on AS on_date, CONCAT('e', e.id) AS ref, e.description, CASE WHEN e.nature = 'receita' THEN e.amount_cents ELSE -CAST(e.amount_cents AS SIGNED) END AS amount
              FROM erp_entries e WHERE e.bank_account_id = :b1 AND e.paid_on IS NOT NULL AND e.status = 'active' AND e.deleted_at IS NULL
            UNION ALL
            SELECT t.transferred_on, CONCAT('t', t.id), t.description, CASE WHEN t.to_bank_account_id = :b2 THEN t.amount_cents ELSE -CAST(t.amount_cents AS SIGNED) END
              FROM erp_bank_transfers t WHERE (t.to_bank_account_id = :b3 OR t.from_bank_account_id = :b4) AND t.status = 'active'
        ) x WHERE 1 = 1{$since}";
        $params = ['b1' => $bankAccountId, 'b2' => $bankAccountId, 'b3' => $bankAccountId, 'b4' => $bankAccountId]
            + ($bank['opened_on'] !== null ? ['since' => (string)$bank['opened_on']] : []);
        $before = $this->pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM ({$moves}) y WHERE y.on_date < :f");
        $before->execute($params + ['f' => $from]);
        $opening = (int)$bank['opening'] + (int)$before->fetchColumn();
        $period = $this->pdo->prepare("SELECT on_date, ref, description, amount FROM ({$moves}) y WHERE y.on_date BETWEEN :f AND :t ORDER BY on_date, ref LIMIT " . (self::MAX_ROWS + 1));
        $period->execute($params + ['f' => $from, 't' => $to]);
        $rows = $period->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d movimentos: reduza o período.', self::MAX_ROWS));
        }
        $lines = self::runningBalance($opening, array_map(static fn (array $r): array => ['on_date' => (string)$r['on_date'], 'ref' => (string)$r['ref'],
            'description' => (string)$r['description'], 'amount_cents' => (int)$r['amount']], $rows));
        $in = array_sum(array_map(static fn (array $l): int => max(0, $l['amount_cents']), $lines));
        $out = array_sum(array_map(static fn (array $l): int => max(0, -$l['amount_cents']), $lines));
        return ['bank_account_id' => (int)$bank['id'], 'name' => (string)$bank['name'], 'from' => $from, 'to' => $to, 'opening_cents' => $opening,
            'rows' => $lines, 'totals' => ['in_cents' => $in, 'out_cents' => $out, 'closing_cents' => $opening + $in - $out]];
    }

    /**
     * Rateio das despesas de um condomínio pela fração ideal (AP-062 a AP-067):
     *  unit (AP-062): total por unidade; compare (AP-063): duas competências por unidade;
     *  unit_account (AP-064): unidade × conta; unit_entries (AP-065): cada despesa para uma unidade;
     *  entries (AP-066): cada despesa × unidade; account (AP-067): por conta, total e maior/menor parte.
     */
    public function apportionment(User $user, int $condominiumId, string $competence, string $view, ?string $compareWith = null, ?int $unitId = null): array
    {
        if (!in_array($view, self::APPORTIONMENT_VIEWS, true)) {
            throw new InvalidArgumentException('Visão inválida: ' . implode(', ', self::APPORTIONMENT_VIEWS) . '.');
        }
        if (!RecurringEntries::isCompetence($competence) || ($view === 'compare' && ($compareWith === null || !RecurringEntries::isCompetence($compareWith)))) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM)' . ($view === 'compare' ? ' e a de comparação.' : '.'));
        }
        $this->scope->assertAccess($user, $condominiumId);
        $stmt = $this->pdo->prepare("SELECT id, label, ideal_fraction FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' ORDER BY label, id");
        $stmt->execute(['c' => $condominiumId]);
        $units = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $missing = array_values(array_map(static fn (array $u): string => (string)$u['label'], array_filter($units, static fn (array $u): bool => $u['ideal_fraction'] === null || (float)$u['ideal_fraction'] <= 0)));
        $base = ['condominium_id' => $condominiumId, 'competence' => $competence, 'view' => $view, 'missing_fraction' => $missing, 'rows' => []];
        if ($units === [] || $missing !== []) {
            return $base + ['blocked' => true, 'message' => $units === [] ? 'O condomínio não tem unidades ativas.' : 'Há unidades sem fração ideal: o rateio não é calculado.'];
        }
        if ($view === 'unit_entries' && ($unitId === null || !in_array($unitId, array_map('intval', array_column($units, 'id')), true))) {
            throw new InvalidArgumentException('Escolha uma unidade ativa deste condomínio.');
        }
        $fractions = [];
        $labels = [];
        foreach ($units as $unit) {
            $fractions[(int)$unit['id']] = $unit['ideal_fraction'];
            $labels[(int)$unit['id']] = (string)$unit['label'];
        }
        $expenses = $this->expenses($condominiumId, $competence);
        $shares = self::shares($expenses, $fractions);
        // Despesas × unidades é a matriz em memória: o teto vale para ela, em toda visão (ADR 0068).
        self::assertMatrix(count($expenses), count($fractions));
        $rows = [];
        if ($view === 'unit' || $view === 'compare') {
            $previousExpenses = $view === 'compare' ? $this->expenses($condominiumId, (string)$compareWith) : [];
            self::assertMatrix(count($previousExpenses), count($fractions));
            $other = $view === 'compare' ? self::shares($previousExpenses, $fractions) : [];
            foreach ($fractions as $id => $fraction) {
                $amount = array_sum(array_map(static fn (array $s): int => $s[$id], $shares));
                $row = ['unit_id' => $id, 'unit_label' => $labels[$id], 'ideal_fraction' => (string)$fraction, 'amount_cents' => $amount];
                if ($view === 'compare') {
                    $previous = array_sum(array_map(static fn (array $s): int => $s[$id], $other));
                    $row = ['unit_id' => $id, 'unit_label' => $labels[$id], 'ideal_fraction' => (string)$fraction, 'a_cents' => $amount, 'b_cents' => $previous] + self::variation($amount, $previous);
                }
                $rows[] = $row;
            }
            $base['compare_with'] = $compareWith;
        } elseif ($view === 'unit_account' || $view === 'account') {
            $byAccount = [];
            foreach ($expenses as $expense) {
                $key = $expense['code'];
                $byAccount[$key] ??= ['code' => (string)$expense['code'], 'account' => (string)$expense['account'], 'amount_cents' => 0, 'units' => array_map(static fn (): int => 0, $fractions)];
                $byAccount[$key]['amount_cents'] += (int)$expense['amount_cents'];
                foreach ($shares[(int)$expense['id']] as $unit => $cents) {
                    $byAccount[$key]['units'][$unit] += $cents;
                }
            }
            foreach ($byAccount as $account) {
                if ($view === 'account') {
                    $rows[] = ['code' => $account['code'], 'account' => $account['account'], 'amount_cents' => $account['amount_cents'],
                        'max_share_cents' => max($account['units']), 'min_share_cents' => min($account['units'])];
                    continue;
                }
                foreach ($account['units'] as $unit => $cents) {
                    $rows[] = ['unit_id' => $unit, 'unit_label' => $labels[$unit], 'code' => $account['code'], 'account' => $account['account'], 'amount_cents' => $cents];
                    if (count($rows) > self::MAX_ROWS) {
                        throw new InvalidArgumentException(sprintf('Mais de %d linhas: use a visão por unidade ou por conta.', self::MAX_ROWS));
                    }
                }
            }
        } else {
            foreach ($expenses as $expense) {
                foreach ($shares[(int)$expense['id']] as $unit => $cents) {
                    if ($view === 'unit_entries' && $unit !== $unitId) {
                        continue;
                    }
                    $rows[] = ['unit_id' => $unit, 'unit_label' => $labels[$unit], 'entry_id' => (int)$expense['id'], 'description' => (string)$expense['description'],
                        'code' => (string)$expense['code'], 'expense_cents' => (int)$expense['amount_cents'], 'amount_cents' => $cents];
                    if (count($rows) > self::MAX_ROWS) {
                        throw new InvalidArgumentException(sprintf('Mais de %d linhas: use a visão por unidade ou escolha uma unidade.', self::MAX_ROWS));
                    }
                }
            }
        }
        // array_merge, não "+": $base já tem 'rows' => [], e a união manteria a lista vazia.
        return array_merge($base, ['blocked' => false, 'rows' => $rows, 'totals' => ['expenses' => count($expenses),
            'amount_cents' => array_sum(array_map(static fn (array $e): int => (int)$e['amount_cents'], $expenses))]]);
    }

    /**
     * AP-068 (lançamentos) e AP-069 (detalhado: fornecedor, conta bancária, documento e origem), com
     * filtros por natureza e situação, na faixa de competência.
     */
    public function entries(User $user, ?int $condominiumId, string $from, string $to, array $filters = [], bool $detailed = false): array
    {
        [$from, $to] = self::competences($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'detailed' => $detailed, 'rows' => [], 'totals' => ['entries' => 0, 'receita_cents' => 0, 'despesa_cents' => 0]];
        if ($ids === []) {
            return $result;
        }
        $where = 'e.condominium_id IN (' . implode(',', $ids) . ') AND e.deleted_at IS NULL AND e.competence BETWEEN :f AND :t';
        $params = ['f' => $from, 't' => $to];
        if (in_array($filters['nature'] ?? null, ['receita', 'despesa'], true)) {
            $where .= ' AND e.nature = :n';
            $params['n'] = $filters['nature'];
        }
        $stmt = $this->pdo->prepare("SELECT e.*, c.name AS condominium_name, a.code, a.name AS account, u.label AS unit_label, s.legal_name AS supplier,
                b.name AS bank_account, cc.name AS cost_center
            FROM erp_entries e JOIN condominiums c ON c.id = e.condominium_id JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            LEFT JOIN erp_units u ON u.id = e.unit_id LEFT JOIN erp_suppliers s ON s.id = e.supplier_id LEFT JOIN erp_bank_accounts b ON b.id = e.bank_account_id
            LEFT JOIN erp_cost_centers cc ON cc.id = e.cost_center_id
            WHERE {$where} ORDER BY c.name, e.due_on, e.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($entries) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d lançamentos: reduza a faixa ou escolha um condomínio.', self::MAX_ROWS));
        }
        $asOf = date('Y-m-d');
        $situation = isset(self::SITUATIONS[$filters['situation'] ?? '']) ? $filters['situation'] : null;
        foreach ($entries as $e) {
            $state = self::situation($e, $asOf);
            if ($situation !== null && $state !== $situation) {
                continue;
            }
            $row = ['entry_id' => (int)$e['id'], 'condominium_name' => (string)$e['condominium_name'], 'nature' => (string)$e['nature'], 'description' => (string)$e['description'],
                'code' => (string)$e['code'], 'account' => (string)$e['account'], 'unit_label' => $e['unit_label'], 'competence' => (string)$e['competence'],
                'due_on' => (string)$e['due_on'], 'paid_on' => $e['paid_on'], 'situation' => $state, 'amount_cents' => (int)$e['amount_cents']];
            if ($detailed) {
                $row += ['supplier' => $e['supplier'], 'bank_account' => $e['bank_account'], 'cost_center' => $e['cost_center'], 'document_number' => $e['document_number'],
                    'origin' => match (true) {
                        $e['invoice_item_id'] !== null => 'fatura', $e['agreement_id'] !== null => 'acordo', ($e['extra_charge_id'] ?? null) !== null => 'cobrança extra ou multa',
                        ($e['recurring_entry_id'] ?? null) !== null => 'lançamento fixo', ($e['import_id'] ?? null) !== null => 'importação', default => 'manual',
                    }];
            }
            $result['rows'][] = $row;
            $result['totals']['entries']++;
            if ($state !== 'cancelled') {
                $result['totals'][$e['nature'] . '_cents'] += (int)$e['amount_cents'];
            }
        }
        return $result;
    }

    /** AP-070: boletos com vencimento no período, por situação, com valores emitidos e pagos. */
    public function bankCharges(User $user, ?int $condominiumId, string $from, string $to, ?string $status = null): array
    {
        [$from, $to] = CollectionReports::period($from, $to);
        $ids = $this->ids($user, $condominiumId);
        $result = ['from' => $from, 'to' => $to, 'rows' => [], 'totals' => ['charges' => 0, 'amount_cents' => 0, 'paid_cents' => 0, 'by_status' => []]];
        if ($ids === []) {
            return $result;
        }
        $where = 'ch.condominium_id IN (' . implode(',', $ids) . ') AND ch.due_on BETWEEN :f AND :t';
        $params = ['f' => $from, 't' => $to];
        if (in_array($status, ['pending', 'registered', 'rejected', 'paid', 'cancelled'], true)) {
            $where .= ' AND ch.status = :s';
            $params['s'] = $status;
        }
        $stmt = $this->pdo->prepare("SELECT ch.id, ch.condominium_id, c.name AS condominium_name, ch.our_number, ch.status, ch.amount_cents, ch.due_on, ch.paid_cents, ch.paid_on,
                b.name AS bank_account, u.label AS unit_label, e.description
            FROM erp_bank_charges ch JOIN condominiums c ON c.id = ch.condominium_id JOIN erp_bank_accounts b ON b.id = ch.bank_account_id
            JOIN erp_entries e ON e.id = ch.entry_id LEFT JOIN erp_units u ON u.id = e.unit_id
            WHERE {$where} ORDER BY c.name, ch.due_on, ch.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d boletos: reduza o período.', self::MAX_ROWS));
        }
        foreach ($rows as $r) {
            $result['rows'][] = ['charge_id' => (int)$r['id'], 'condominium_name' => (string)$r['condominium_name'], 'our_number' => (string)$r['our_number'],
                'status' => (string)$r['status'], 'unit_label' => $r['unit_label'], 'description' => (string)$r['description'], 'bank_account' => (string)$r['bank_account'],
                'due_on' => (string)$r['due_on'], 'amount_cents' => (int)$r['amount_cents'], 'paid_on' => $r['paid_on'], 'paid_cents' => $r['paid_cents'] !== null ? (int)$r['paid_cents'] : null];
            $result['totals']['charges']++;
            $result['totals']['amount_cents'] += (int)$r['amount_cents'];
            $result['totals']['paid_cents'] += (int)($r['paid_cents'] ?? 0);
            $result['totals']['by_status'][(string)$r['status']] = ($result['totals']['by_status'][(string)$r['status']] ?? 0) + 1;
        }
        return $result;
    }

    /**
     * Tabela para exportar (regra pura), com as células saneadas por CollectionReports::csvCell.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(string $kind, array $report): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $date = static fn (mixed $d): string => is_string($d) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m) ? "{$m[3]}/{$m[2]}/{$m[1]}" : (string)($d ?? '');
        $number = static fn (mixed $n): string => str_replace('.', ',', (string)($n ?? ''));
        $columns = match ($kind) {
            'consumption' => ['Condomínio' => 'condominium_name', 'Unidade' => 'unit_label', 'Tipo' => 'kind', 'Leituras' => 'readings', 'Consumo' => ['consumption', $number],
                'Valor' => ['amount_cents', $money], 'Cobradas' => 'billed'],
            'cost-centers' => ['Condomínio' => 'condominium_name', 'Natureza' => 'nature', 'Código' => 'code', 'Centro de custo' => 'cost_center',
                'Previsto' => ['planned_cents', $money], 'Realizado' => ['realized_cents', $money], 'Em aberto' => ['open_cents', $money]],
            'comparison' => ['Código' => 'code', 'Conta' => 'name', 'Natureza' => 'nature', 'Período A' => ['a_cents', $money], 'Período B' => ['b_cents', $money],
                'Diferença' => ['difference_cents', $money], 'Variação (%)' => ['percent', $number]],
            'statement-accounts' => ['Código' => 'code', 'Conta' => 'account', 'Natureza' => 'nature', 'Lançamentos' => 'entries', 'Previsto' => ['planned_cents', $money],
                'Realizado' => ['realized_cents', $money], 'Em aberto' => ['open_cents', $money]],
            'statement-analytic' => ['Código' => 'code', 'Conta' => 'account', 'Natureza' => 'nature', 'Descrição' => 'description', 'Unidade' => 'unit_label',
                'Competência' => 'competence', 'Vencimento' => ['due_on', $date], 'Pagamento' => ['paid_on', $date], 'Situação' => 'situation', 'Valor' => ['amount_cents', $money]],
            'statement-situation' => ['Natureza' => 'nature', 'Situação' => 'situation_label', 'Lançamentos' => 'entries', 'Valor' => ['amount_cents', $money]],
            'account-statement' => ['Data' => ['on_date', $date], 'Histórico' => 'description', 'Valor' => ['amount_cents', $money], 'Saldo' => ['balance_cents', $money]],
            'apportionment-unit' => ['Unidade' => 'unit_label', 'Fração ideal' => ['ideal_fraction', $number], 'Valor' => ['amount_cents', $money]],
            'apportionment-compare' => ['Unidade' => 'unit_label', 'Fração ideal' => ['ideal_fraction', $number], 'Competência' => ['a_cents', $money],
                'Comparação' => ['b_cents', $money], 'Diferença' => ['difference_cents', $money], 'Variação (%)' => ['percent', $number]],
            'apportionment-unit_account' => ['Unidade' => 'unit_label', 'Código' => 'code', 'Conta' => 'account', 'Valor' => ['amount_cents', $money]],
            'apportionment-unit_entries', 'apportionment-entries' => ['Unidade' => 'unit_label', 'Despesa' => 'description', 'Código' => 'code',
                'Valor da despesa' => ['expense_cents', $money], 'Parte da unidade' => ['amount_cents', $money]],
            'apportionment-account' => ['Código' => 'code', 'Conta' => 'account', 'Valor' => ['amount_cents', $money], 'Maior parte' => ['max_share_cents', $money],
                'Menor parte' => ['min_share_cents', $money]],
            'entries' => ['Condomínio' => 'condominium_name', 'Natureza' => 'nature', 'Descrição' => 'description', 'Código' => 'code', 'Unidade' => 'unit_label',
                'Competência' => 'competence', 'Vencimento' => ['due_on', $date], 'Pagamento' => ['paid_on', $date], 'Situação' => 'situation', 'Valor' => ['amount_cents', $money]],
            'entries-detailed' => ['Condomínio' => 'condominium_name', 'Natureza' => 'nature', 'Descrição' => 'description', 'Código' => 'code', 'Unidade' => 'unit_label',
                'Fornecedor' => 'supplier', 'Centro de custo' => 'cost_center', 'Conta bancária' => 'bank_account', 'Documento' => 'document_number', 'Origem' => 'origin',
                'Competência' => 'competence', 'Vencimento' => ['due_on', $date], 'Pagamento' => ['paid_on', $date], 'Situação' => 'situation', 'Valor' => ['amount_cents', $money]],
            'bank-charges' => ['Condomínio' => 'condominium_name', 'Nosso número' => 'our_number', 'Situação' => 'status', 'Unidade' => 'unit_label', 'Descrição' => 'description',
                'Conta bancária' => 'bank_account', 'Vencimento' => ['due_on', $date], 'Valor' => ['amount_cents', $money], 'Pagamento' => ['paid_on', $date], 'Valor pago' => ['paid_cents', $money]],
            default => throw new InvalidArgumentException('Relatório sem exportação.'),
        };
        $rows = [];
        foreach ($report['rows'] ?? [] as $row) {
            $line = [];
            foreach ($columns as $spec) {
                [$field, $format] = is_array($spec) ? $spec : [$spec, null];
                $value = $row[$field] ?? null;
                $line[] = CollectionReports::csvCell($format !== null && $value !== null ? $format($value) : (string)($value ?? ''));
            }
            $rows[] = $line;
        }
        return ['header' => array_keys($columns), 'rows' => $rows];
    }

    /** Teto de células do rateio (regra pura, ADR 0068): despesas × unidades. */
    public const MAX_APPORTIONMENT_CELLS = 500000;

    public static function assertMatrix(int $expenses, int $units): void
    {
        if ($expenses > self::MAX_ROWS || $expenses * $units > self::MAX_APPORTIONMENT_CELLS) {
            throw new InvalidArgumentException(sprintf('A competência tem %d despesas para %d unidades: é grande demais para o rateio na tela. Fale com o suporte.', $expenses, $units));
        }
    }

    /** @return list<array> despesas válidas da competência, com a conta do plano */
    private function expenses(int $condominiumId, string $competence): array
    {
        $stmt = $this->pdo->prepare("SELECT e.id, e.description, e.amount_cents, a.code, a.name AS account FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id = :c AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence = :m ORDER BY a.code, e.id");
        $stmt->execute(['c' => $condominiumId, 'm' => $competence]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
}
