<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Balanço Patrimonial (AP-081, ADR 0167) de um condomínio numa data, calculado na leitura (sem lançamento
 * físico) a partir do que o sistema já registra:
 *
 * - 7.1 Disponível = saldos das contas bancárias na data (AccountingReports::accountBalances, AP-073);
 * - 7.2 Contas a receber = receitas com competência até o mês da data e não pagas até a data
 *   (vencidas e a vencer);
 * - 8.1 Contas a pagar = despesas com competência até o mês da data e não pagas até a data;
 * - 9.2 Resultado do exercício = DRE por competência de 1º de janeiro (ou da data do saldo inicial do
 *   PL, se for depois) até a data (AccountingReports::incomeStatement, AP-082);
 * - 9.1 Resultados acumulados = saldo inicial da conta 9.1 (AP-161) + DRE dos anos anteriores desde a data
 *   desse saldo. A virada do ano é só virtual: no ano seguinte o resultado entra nos acumulados.
 * - As demais contas patrimoniais analíticas (fundos 8.2 e 8.3, contas próprias do condomínio) valem o
 *   saldo inicial (AP-161) com data até a data do balanço, mais os lançamentos patrimoniais com partida
 *   dobrada (ADR 0169), que não passam pela DRE nem pelo banco.
 *
 * Não força o fechamento: devolve "diferenca" = ativo − (passivo + PL) e um aviso quando não é zero.
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class BalanceSheet
{
    public const NATURES = ['asset', 'liability', 'equity'];
    public const MAX_ACCOUNTS = 2000;

    /** Contas calculadas, pelo código do plano padrão (migration 20261109_0704). */
    public const ROLES = [
        '7.1' => ['nature' => 'asset', 'name' => 'Disponível', 'role' => 'bank'],
        '7.2' => ['nature' => 'asset', 'name' => 'Contas a receber', 'role' => 'receivable'],
        '8.1' => ['nature' => 'liability', 'name' => 'Contas a pagar', 'role' => 'payable'],
        '9.1' => ['nature' => 'equity', 'name' => 'Resultados acumulados', 'role' => 'retained'],
        '9.2' => ['nature' => 'equity', 'name' => 'Resultado do exercício', 'role' => 'current'],
    ];
    public const GROUPS = ['7' => ['asset', 'Ativo'], '8' => ['liability', 'Passivo'], '9' => ['equity', 'Patrimônio Líquido']];
    public const LIMITATIONS = [
        'Contas a receber e a pagar seguem a competência dos lançamentos; pagamento adiantado (competência futura) entra no Disponível e não no resultado.',
        'Fundos e outras contas patrimoniais mudam pelo saldo inicial (AP-161) e pelos lançamentos patrimoniais (ADR 0169), que não passam pela DRE.',
        'Lançamento pago sem conta bancária entra no resultado e não no Disponível.',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    public static function date(mixed $date): string
    {
        $parsed = is_string($date) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if ($parsed === false || $parsed->format('Y-m-d') !== $date || $date < '2000-01-01') {
            throw new InvalidArgumentException('Informe a data do balanço (AAAA-MM-DD).');
        }
        return $date;
    }

    /**
     * Monta o balanço (regra pura).
     *
     * @param list<array{code:string, name:string, nature:string, kind:string, opening_cents:?int}> $accounts contas patrimoniais do plano,
     *   com o saldo inicial válido na data (null = sem saldo)
     * @param array{banks:list<array{name:string, closing_cents:int}>, receivable_cents:int, receivable_overdue_cents:int, payable_cents:int,
     *   payable_overdue_cents:int, retained_prior_cents:int, current_result_cents:int} $values
     */
    public static function compose(array $accounts, array $values, string $date): array
    {
        $names = [];
        $analytic = [];
        $warnings = [];
        foreach (self::GROUPS as $code => [$nature, $name]) {
            $names[$nature . '|' . $code] = $name;
        }
        foreach (self::ROLES as $code => $role) {
            $analytic[$role['nature'] . '|' . $code] = ['code' => $code, 'name' => $role['name'], 'nature' => $role['nature'], 'role' => $role['role'], 'amount_cents' => 0];
        }
        foreach ($accounts as $account) {
            $nature = (string)$account['nature'];
            $code = (string)$account['code'];
            if (!in_array($nature, self::NATURES, true)) {
                continue;
            }
            $key = $nature . '|' . $code;
            $role = self::roleOf($code, $nature);
            if ($role !== null) {
                $roleKey = self::ROLES[$role]['nature'] . '|' . $role;
                if ($code === $role) {
                    $analytic[$roleKey]['name'] = (string)$account['name'];
                    if ($account['kind'] === 'sintetica') {
                        $names[$key] = (string)$account['name'];
                    }
                }
                if ($account['opening_cents'] !== null && self::ROLES[$role]['role'] !== 'retained') {
                    $warnings[] = ['code' => 'opening_ignored', 'message' => "O saldo inicial da conta {$code} não entra: o valor dela é calculado."];
                }
                continue;
            }
            if ($account['kind'] === 'sintetica') {
                $names[$key] = (string)$account['name'];
                continue;
            }
            // Saldo inicial (AP-161) + lançamentos patrimoniais (ADR 0169).
            $analytic[$key] = ['code' => $code, 'name' => (string)$account['name'], 'nature' => $nature, 'role' => 'opening',
                'amount_cents' => (int)($account['opening_cents'] ?? 0) + (int)($values['movements'][$key] ?? 0)];
        }

        // Cada conta com o tipo (ADR 0170): a aplicação aparece separada da conta corrente dentro do Disponível.
        $banks = array_map(static fn (array $b): array => ['name' => (string)$b['name'], 'kind' => (string)($b['kind'] ?? 'corrente'), 'amount_cents' => (int)$b['closing_cents']],
            $values['banks'] ?? []);
        $byKind = ['checking_cents' => 0, 'savings_cents' => 0, 'investments_cents' => 0];
        foreach ($banks as $bank) {
            $byKind[['poupanca' => 'savings_cents', 'aplicacao' => 'investments_cents'][$bank['kind']] ?? 'checking_cents'] += $bank['amount_cents'];
        }
        $retainedOpening = 0;
        foreach ($accounts as $account) {
            if ((string)$account['code'] === '9.1' && $account['nature'] === 'equity') {
                $retainedOpening = (int)($account['opening_cents'] ?? 0);
            }
        }
        $amounts = [
            'bank' => array_sum(array_column($banks, 'amount_cents')),
            'receivable' => (int)($values['receivable_cents'] ?? 0),
            'payable' => (int)($values['payable_cents'] ?? 0),
            'retained' => $retainedOpening + (int)($values['retained_prior_cents'] ?? 0) + (int)($values['retained_movements_cents'] ?? 0),
            'current' => (int)($values['current_result_cents'] ?? 0) + (int)($values['current_movements_cents'] ?? 0),
        ];
        foreach ($analytic as &$row) {
            if ($row['role'] !== 'opening') {
                $row['amount_cents'] = $amounts[$row['role']];
            }
            if ($row['role'] === 'bank') {
                $row['lines'] = $banks;
                $row += $byKind;
            } elseif ($row['role'] === 'receivable') {
                $row['overdue_cents'] = (int)($values['receivable_overdue_cents'] ?? 0);
            } elseif ($row['role'] === 'payable') {
                $row['overdue_cents'] = (int)($values['payable_overdue_cents'] ?? 0);
            }
        }
        unset($row);

        $groups = [];
        foreach (self::NATURES as $nature) {
            $rows = array_values(array_filter($analytic, static fn (array $r): bool => $r['nature'] === $nature));
            $groups[$nature] = ['rows' => self::tree($rows, $names), 'total_cents' => array_sum(array_column($rows, 'amount_cents'))];
        }
        $assets = $groups['asset']['total_cents'];
        $liabilitiesEquity = $groups['liability']['total_cents'] + $groups['equity']['total_cents'];
        $difference = $assets - $liabilitiesEquity;
        if ($difference !== 0) {
            $warnings[] = ['code' => 'unbalanced', 'message' => 'O ativo não fecha com passivo + patrimônio líquido. Confira o saldo inicial das contas bancárias e do PL (AP-161).'];
        }
        return [
            'date' => $date, 'year_start' => substr($date, 0, 4) . '-01-01',
            'assets' => $groups['asset'], 'liabilities' => $groups['liability'], 'equity' => $groups['equity'],
            'totals' => ['assets_cents' => $assets, 'liabilities_cents' => $groups['liability']['total_cents'], 'equity_cents' => $groups['equity']['total_cents'],
                'liabilities_and_equity_cents' => $liabilitiesEquity],
            'balanced' => $difference === 0, 'diferenca' => $difference,
            'warnings' => $warnings, 'limitations' => self::LIMITATIONS,
        ];
    }

    public function build(User $user, ?int $condominiumId, mixed $date): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $date = self::date($date);
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $c = $condominiumId;
        $month = substr($date, 0, 7);
        $yearStart = substr($date, 0, 4) . '-01-01';

        $stmt = $this->pdo->prepare("SELECT a.code, a.name, a.nature, a.kind, a.condominium_id, o.amount_cents, o.opened_on
            FROM erp_chart_accounts a LEFT JOIN erp_chart_account_openings o ON o.chart_account_id = a.id AND o.condominium_id = :c1 AND o.opened_on <= :d
            WHERE a.deleted_at IS NULL AND a.status = 'active' AND a.nature IN ('asset', 'liability', 'equity') AND (a.condominium_id IS NULL OR a.condominium_id = :c2)
            ORDER BY a.condominium_id IS NULL DESC, a.code LIMIT " . (self::MAX_ACCOUNTS + 1));
        $stmt->execute(['c1' => $c, 'c2' => $c, 'd' => $date]);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($raw) > self::MAX_ACCOUNTS;
        $accounts = [];
        $openedOn = [];
        $retainedSince = null;
        foreach (array_slice($raw, 0, self::MAX_ACCOUNTS) as $r) {
            // A conta do condomínio com o mesmo código substitui a do padrão (o padrão vem primeiro).
            $accounts[$r['nature'] . '|' . $r['code']] = ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'],
                'kind' => (string)$r['kind'], 'opening_cents' => $r['amount_cents'] !== null ? (int)$r['amount_cents'] : null];
            if ($r['opened_on'] !== null) {
                $openedOn[$r['nature'] . '|' . $r['code']] = (string)$r['opened_on'];
            }
            if ($r['code'] === '9.1' && $r['nature'] === 'equity' && $r['opened_on'] !== null) {
                $retainedSince = (string)$r['opened_on'];
            }
        }

        $open = $this->pdo->prepare("SELECT nature, SUM(amount_cents) AS cents, SUM(CASE WHEN due_on < :d1 THEN amount_cents ELSE 0 END) AS overdue
            FROM erp_entries WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' AND competence <= :m
              AND (paid_on IS NULL OR paid_on > :d2) GROUP BY nature");
        $open->execute(['c' => $c, 'd1' => $date, 'd2' => $date, 'm' => $month]);
        $openBy = [];
        foreach ($open->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $openBy[(string)$r['nature']] = [(int)$r['cents'], (int)$r['overdue']];
        }

        // O saldo inicial do PL (9.1) substitui o histórico anterior à sua data.
        $priorParams = ['c' => $c, 'y' => substr($yearStart, 0, 7)];
        $sinceSql = '';
        if ($retainedSince !== null) {
            $sinceSql = ' AND competence >= :s';
            $priorParams['s'] = substr($retainedSince, 0, 7);
        }
        $prior = $this->pdo->prepare("SELECT nature, SUM(amount_cents) AS cents FROM erp_entries WHERE condominium_id = :c AND deleted_at IS NULL
            AND status = 'active' AND competence < :y{$sinceSql} GROUP BY nature");
        $prior->execute($priorParams);
        $priorResult = 0;
        foreach ($prior->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $priorResult += $r['nature'] === 'receita' ? (int)$r['cents'] : ($r['nature'] === 'despesa' ? -(int)$r['cents'] : 0);
        }

        $accounting = new AccountingReports($this->pdo, $this->scope);
        $currentFrom = $retainedSince !== null && $retainedSince > $yearStart ? substr($retainedSince, 0, 7) . '-01' : $yearStart;
        $current = $accounting->incomeStatement($user, $c, $currentFrom, $date, 'competence');
        $banks = $accounting->accountBalances($user, $c, $date);
        $kinds = (new Investments($this->pdo, $this->scope))->kinds(array_column($banks['accounts'], 'bank_account_id'));
        // Lançamentos patrimoniais (ADR 0169): o saldo inicial do PL também substitui os movimentos de PL anteriores à data dele.
        if ($retainedSince !== null && !isset($openedOn['equity|9.2'])) {
            $openedOn['equity|9.2'] = $retainedSince;
        }
        $moves = PatrimonialEntries::balances((new PatrimonialEntries($this->pdo, $this->scope))->movements($c, $date), $openedOn, $yearStart);

        return ['condominium_id' => $c, 'truncated' => $truncated || !empty($current['truncated'])] + self::compose(array_values($accounts), [
            'banks' => array_map(static fn (array $b): array => $b + ['kind' => $kinds[(int)$b['bank_account_id']]['kind'] ?? 'corrente'], $banks['accounts']),
            'receivable_cents' => $openBy['receita'][0] ?? 0, 'receivable_overdue_cents' => $openBy['receita'][1] ?? 0,
            'payable_cents' => $openBy['despesa'][0] ?? 0, 'payable_overdue_cents' => $openBy['despesa'][1] ?? 0,
            'retained_prior_cents' => $priorResult, 'current_result_cents' => (int)$current['totals']['result_cents'],
            'movements' => $moves['accounts'], 'retained_movements_cents' => $moves['retained'], 'current_movements_cents' => $moves['current'],
        ], $date);
    }

    /** CSV do balanço (regra pura), com as células de texto saneadas por CollectionReports::csvCell. */
    public static function csvTable(array $report): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $cell = static fn (mixed $v): string => CollectionReports::csvCell((string)$v);
        $rows = [];
        foreach (['assets' => 'Ativo', 'liabilities' => 'Passivo', 'equity' => 'Patrimônio Líquido'] as $key => $label) {
            foreach ($report[$key]['rows'] as $r) {
                $rows[] = [$label, $cell($r['code']), $cell(str_repeat('  ', (int)$r['level'] - 1) . $r['name']), $money($r['amount_cents'])];
                foreach ($r['lines'] ?? [] as $line) {
                    $suffix = ($line['kind'] ?? '') === 'aplicacao' ? ' (aplicação)' : (($line['kind'] ?? '') === 'poupanca' ? ' (poupança)' : '');
                    $rows[] = [$label, '', $cell(str_repeat('  ', (int)$r['level']) . $line['name'] . $suffix), $money($line['amount_cents'])];
                }
            }
            $rows[] = [$label, '', 'Total', $money($report[$key]['total_cents'])];
        }
        $rows[] = ['', '', 'Passivo + Patrimônio Líquido', $money($report['totals']['liabilities_and_equity_cents'])];
        $rows[] = ['', '', 'Diferença', $money($report['diferenca'])];
        return ['header' => ['Grupo', 'Código', 'Conta', 'Valor'], 'rows' => $rows];
    }

    /** Código calculado ao qual a conta pertence (ela mesma ou subconta), ou null. */
    private static function roleOf(string $code, string $nature): ?string
    {
        foreach (self::ROLES as $roleCode => $role) {
            if ($role['nature'] === $nature && ($code === (string)$roleCode || str_starts_with($code, $roleCode . '.'))) {
                return (string)$roleCode;
            }
        }
        return null;
    }

    /**
     * Soma as analíticas nas sintéticas acima delas (regra pura), na ordem do código.
     *
     * @param list<array{code:string, name:string, nature:string, amount_cents:int}> $rows
     * @param array<string, string> $names nome das sintéticas por "natureza|código"
     */
    private static function tree(array $rows, array $names): array
    {
        $tree = [];
        foreach ($rows as $row) {
            $parts = explode('.', $row['code']);
            for ($depth = 1; $depth <= count($parts); $depth++) {
                $code = implode('.', array_slice($parts, 0, $depth));
                $leaf = $depth === count($parts);
                $tree[$code] ??= ['code' => $code, 'name' => $names[$row['nature'] . '|' . $code] ?? $code, 'level' => $depth, 'analytic' => false, 'amount_cents' => 0];
                $tree[$code]['amount_cents'] += $row['amount_cents'];
                if ($leaf) {
                    $tree[$code] = ['analytic' => true, 'name' => $row['name'], 'role' => $row['role']] + $tree[$code]
                        + array_intersect_key($row, ['lines' => true, 'overdue_cents' => true, 'checking_cents' => true, 'savings_cents' => true, 'investments_cents' => true]);
                }
            }
        }
        uksort($tree, static fn (string $a, string $b): int => self::sortable($a) <=> self::sortable($b));
        return array_values($tree);
    }

    private static function sortable(string $code): string
    {
        return implode('.', array_map(static fn (string $p): string => str_pad($p, 6, '0', STR_PAD_LEFT), explode('.', $code)));
    }
}
