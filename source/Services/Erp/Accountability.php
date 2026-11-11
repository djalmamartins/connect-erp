<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Prestação de contas mensal (AP-101, ADR 0166): o pacote de um condomínio num mês, montado só com os
 * services que já existem, sem SQL próprio:
 *
 * - balancete da competência (AP-079, FinancialReports::trialBalance);
 * - DRE do mês por competência (AP-082, AccountingReports::incomeStatement);
 * - saldos das contas bancárias no mês (AP-073, AccountingReports::accountBalances);
 * - inadimplência no último dia do mês (Agreements::delinquency, a mesma do AP-057);
 * - situação do fechamento mensal (AP-030, MonthClosing::status);
 * - fundos do mês (ADR 0177): fundo de reserva e outros fundos pelo Livro Razão (AccountingReports::ledger),
 *   com saldo anterior, entradas, saídas, saldo final e os movimentos.
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404). Somente leitura.
 */
final class Accountability
{
    /** Unidades inadimplentes listadas no pacote; o total sempre considera todas. */
    public const MAX_UNITS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;
    /** @var \Closure(User, int, string): array */
    private \Closure $delinquency;

    /** @param (callable(User, int, string): array)|null $delinquency substitui Agreements::delinquency (testes) */
    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?callable $delinquency = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->delinquency = $delinquency !== null ? \Closure::fromCallable($delinquency)
            : fn (User $user, int $condominiumId, string $asOf): array => (new Agreements($this->pdo, $this->scope))->delinquency($user, $condominiumId, $asOf);
    }

    /** @return array{0:string, 1:string, 2:string} competência, primeiro e último dia */
    public static function month(mixed $month): array
    {
        if (!is_string($month) || !RecurringEntries::isCompetence($month)) {
            throw new InvalidArgumentException('Informe o mês (AAAA-MM).');
        }
        $first = \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
        return [$month, $first->format('Y-m-d'), $first->modify('last day of this month')->format('Y-m-d')];
    }

    public function build(User $user, ?int $condominiumId, mixed $month): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$competence, $from, $to] = self::month($month);
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $accounting = new AccountingReports($this->pdo, $this->scope);
        return self::assemble($condominiumId, $competence, $from, $to,
            (new MonthClosing($this->pdo, $this->scope))->status($user, $condominiumId, $competence),
            (new FinancialReports($this->pdo, $this->scope))->trialBalance($user, $condominiumId, $competence, $competence),
            $accounting->incomeStatement($user, $condominiumId, $from, $to, 'competence'),
            $accounting->accountBalances($user, $condominiumId, null, $from, $to),
            ($this->delinquency)($user, $condominiumId, $to),
            // Fundos (ADR 0177): o Razão das contas de passivo do mês, para os números baterem com o Razão e o Balanço.
            $accounting->ledger($user, $condominiumId, $from, $to, 'competence', '8'));
    }

    /**
     * O pacote do mês em uma tabela para CSV (regra pura, ADR 0178). Cada linha diz a seção:
     * resumo, fechamento, DRE, bancos, fundos (com os movimentos), inadimplência e avisos.
     * Texto passa por CollectionReports::csvCell (neutraliza fórmula); valores em reais com vírgula.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(array $pack): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $cell = static fn (mixed $v): string => CollectionReports::csvCell((string)$v);
        $s = $pack['summary'] ?? [];
        $rows = [];
        foreach ([
            'Receitas' => 'revenue_cents', 'Despesas' => 'expense_cents', 'Resultado' => 'result_cents',
            'Entradas no caixa' => 'cash_in_cents', 'Saídas do caixa' => 'cash_out_cents',
            'Saldo bancário anterior' => 'bank_opening_cents', 'Saldo bancário final' => 'bank_closing_cents',
            'Receitas da competência em aberto' => 'open_revenue_cents', 'Despesas da competência em aberto' => 'open_expense_cents',
            'Contas vencidas no fim do mês' => 'delinquent_cents',
            'Fundos: saldo anterior' => 'funds_opening_cents', 'Fundos: saldo final' => 'funds_closing_cents',
        ] as $label => $key) {
            $rows[] = ['Resumo', '', $label, '', '', '', $money($s[$key] ?? 0)];
        }
        $status = ['closed' => 'Fechado', 'open' => 'Aberto', 'reopened' => 'Reaberto'][(string)($pack['closing']['status'] ?? 'open')] ?? 'Aberto';
        $rows[] = ['Fechamento', '', $cell('Mês ' . ($pack['competence'] ?? '') . ': ' . $status), '', '', '', ''];
        foreach ($pack['income_statement']['rows'] ?? [] as $r) {
            $rows[] = ['DRE', $cell($r['code']), $cell(str_repeat('  ', max(0, (int)($r['level'] ?? 1) - 1)) . $r['name']), '', '', '', $money($r['amount_cents'])];
        }
        foreach ($pack['bank_accounts']['accounts'] ?? [] as $b) {
            $rows[] = ['Bancos', '', $cell($b['name'] ?? ''), $money($b['opening_cents'] ?? 0), $money($b['received_cents'] ?? 0), $money($b['paid_cents'] ?? 0),
                $money($b['closing_cents'] ?? 0)];
        }
        foreach ($pack['funds']['accounts'] ?? [] as $f) {
            $rows[] = ['Fundos', $cell($f['code']), $cell($f['name']), $money($f['opening_cents']), $money($f['in_cents']), $money($f['out_cents']), $money($f['closing_cents'])];
            foreach ($f['movements'] ?? [] as $m) {
                $date = is_string($m['on_date'] ?? null) && $m['on_date'] !== '' ? date('d/m/Y', (int)strtotime($m['on_date'])) . ' ' : '';
                $rows[] = ['Fundos', '', $cell('  ' . $date . $m['description']), '', $money($m['in_cents']), $money($m['out_cents']), $money($m['balance_cents'])];
            }
        }
        foreach ($pack['delinquency']['units'] ?? [] as $u) {
            $rows[] = ['Inadimplência', '', $cell('Unidade ' . $u['unit_label'] . ' (' . (int)$u['max_days_late'] . ' dias)'), '', '', '', $money($u['amount_cents'])];
        }
        foreach ($pack['warnings'] ?? [] as $w) {
            $rows[] = ['Avisos', '', $cell($w['message'] ?? ''), '', '', '', ''];
        }
        if (!empty($pack['truncated'])) {
            $rows[] = ['Avisos', '', 'Relatório parcial: algum bloco passou do limite de linhas.', '', '', '', ''];
        }
        return ['header' => ['Seção', 'Código', 'Item', 'Saldo anterior', 'Entradas', 'Saídas', 'Valor ou saldo final'], 'rows' => $rows];
    }

    /**
     * Fundos do mês (regra pura, ADR 0177): das contas do Razão, só as de passivo lançadas à mão (fundo de reserva,
     * outros fundos e as do condomínio). Contas a pagar (8.1) é calculada e fica de fora. Por fundo: saldo anterior,
     * entradas (a crédito, como a apropriação), saídas (a débito, como o uso) e saldo final, com os movimentos.
     *
     * @return array{accounts:list<array>, totals:array{opening_cents:int, in_cents:int, out_cents:int, closing_cents:int}}
     */
    public static function funds(array $ledger): array
    {
        $accounts = [];
        foreach ($ledger['accounts'] ?? [] as $a) {
            if (($a['nature'] ?? null) !== 'liability' || PatrimonialEntries::computed((string)$a['code'])) {
                continue;
            }
            $accounts[] = ['code' => (string)$a['code'], 'name' => (string)$a['name'], 'opening_cents' => (int)$a['opening_cents'],
                'in_cents' => (int)$a['credit_cents'], 'out_cents' => (int)$a['debit_cents'], 'closing_cents' => (int)$a['closing_cents'],
                'movements' => array_map(static fn (array $l): array => ['on_date' => $l['on_date'], 'description' => (string)$l['description'], 'source' => (string)$l['source'],
                    'in_cents' => (int)$l['credit_cents'], 'out_cents' => (int)$l['debit_cents'], 'balance_cents' => (int)$l['balance_cents']], $a['lines'] ?? [])];
        }
        $sum = static fn (string $key): int => array_sum(array_column($accounts, $key));
        return ['accounts' => $accounts, 'totals' => ['opening_cents' => $sum('opening_cents'), 'in_cents' => $sum('in_cents'),
            'out_cents' => $sum('out_cents'), 'closing_cents' => $sum('closing_cents')]];
    }

    /**
     * Junta as partes (regra pura): resume o fechamento e a inadimplência e confere o pacote.
     * Avisos: mês não fechado, receita/despesa da competência ainda em aberto e contas vencidas no fim do mês.
     */
    public static function assemble(int $condominiumId, string $competence, string $from, string $to, array $closing, array $trial,
        array $income, array $balances, array $delinquency, array $ledger = []): array
    {
        $funds = self::funds($ledger);
        $units = array_map(static fn (array $u): array => ['unit_id' => $u['unit_id'], 'unit_label' => (string)$u['unit_label'], 'entries' => count($u['entries'] ?? []),
            'amount_cents' => (int)$u['amount_cents'], 'charges_cents' => (int)($u['charges_cents'] ?? 0), 'total_cents' => (int)($u['total_cents'] ?? $u['amount_cents']),
            'oldest_due_on' => (string)$u['oldest_due_on'], 'max_days_late' => (int)$u['max_days_late']], $delinquency['units'] ?? []);
        usort($units, static fn (array $a, array $b): int => [$b['amount_cents'], $a['unit_label']] <=> [$a['amount_cents'], $b['unit_label']]);
        $delinquentTruncated = count($units) > self::MAX_UNITS;
        $t = $delinquency['totals'] ?? [];
        $delinquent = ['as_of' => (string)($delinquency['as_of'] ?? $to), 'truncated' => $delinquentTruncated, 'units' => array_slice($units, 0, self::MAX_UNITS),
            'totals' => ['units' => (int)($t['units'] ?? count($units)), 'entries' => (int)($t['entries'] ?? 0), 'amount_cents' => (int)($t['amount_cents'] ?? 0),
                'charges_cents' => (int)($t['charges_cents'] ?? 0), 'total_cents' => (int)($t['total_cents'] ?? 0), 'buckets' => $t['buckets'] ?? []]];

        $status = (string)($closing['status'] ?? 'open');
        $close = $closing['closing'] ?? null;
        $closingPart = ['status' => $status, 'closed_at' => $close['closed_at'] ?? null, 'closed_by' => $close['closed_by'] ?? null,
            'reopened_at' => $close['reopened_at'] ?? null, 'reopen_reason' => $close['reopen_reason'] ?? null];

        $tt = $trial['totals'] ?? [];
        $openRevenue = (int)($tt['open_revenue_cents'] ?? 0);
        $openExpense = (int)($tt['open_expense_cents'] ?? 0);
        $bank = $balances['totals'] ?? ['opening_cents' => 0, 'received_cents' => 0, 'paid_cents' => 0, 'closing_cents' => 0];
        $warnings = [];
        if ($status !== 'closed') {
            $warnings[] = ['code' => 'month_open', 'message' => 'O mês ainda não foi fechado: os números podem mudar.'];
        }
        if ($openRevenue > 0 || $openExpense > 0) {
            $warnings[] = ['code' => 'open_entries', 'message' => 'Há receitas ou despesas da competência ainda em aberto.'];
        }
        if ($delinquent['totals']['amount_cents'] > 0) {
            $warnings[] = ['code' => 'delinquency', 'message' => 'Há contas vencidas em aberto no fim do mês.'];
        }
        return [
            'condominium_id' => $condominiumId, 'competence' => $competence, 'from' => $from, 'to' => $to,
            'truncated' => $delinquentTruncated || !empty($income['truncated']) || !empty($balances['truncated']) || !empty($ledger['truncated']),
            'closing' => $closingPart,
            'trial_balance' => ['rows' => $trial['rows'] ?? [], 'totals' => $tt],
            'income_statement' => ['rows' => $income['rows'] ?? [], 'totals' => $income['totals'] ?? []],
            'bank_accounts' => ['accounts' => $balances['accounts'] ?? [], 'totals' => $bank],
            'delinquency' => $delinquent,
            'funds' => $funds,
            'summary' => [
                'funds_opening_cents' => $funds['totals']['opening_cents'], 'funds_closing_cents' => $funds['totals']['closing_cents'],
                'revenue_cents' => (int)($income['totals']['revenue_cents'] ?? 0), 'expense_cents' => (int)($income['totals']['expense_cents'] ?? 0),
                'result_cents' => (int)($income['totals']['result_cents'] ?? 0),
                'cash_in_cents' => (int)$bank['received_cents'], 'cash_out_cents' => (int)$bank['paid_cents'],
                'bank_opening_cents' => (int)$bank['opening_cents'], 'bank_closing_cents' => (int)$bank['closing_cents'],
                'open_revenue_cents' => $openRevenue, 'open_expense_cents' => $openExpense,
                'delinquent_cents' => $delinquent['totals']['amount_cents'],
            ],
            'warnings' => $warnings,
        ];
    }
}
