<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Prestação de contas anual (ADR 0182), para a assembleia ordinária: o pacote do ano de um condomínio, montado só
 * com os services que já existem (o mesmo caminho do pacote mensal, ADR 0166):
 *
 * - DRE do ano por competência (AccountingReports::incomeStatement);
 * - Balanço no fim do ano, ou hoje no ano corrente (BalanceSheet::build);
 * - fundos no ano pelo Livro Razão das contas 8 (Accountability::funds, ADR 0177);
 * - previsto contra realizado do ano (ErpSettings::budget e Accountability::budgetSection, ADR 0180);
 * - inadimplência na data (Agreements::delinquency);
 * - meses do ano fechados ou não (erp_month_closings).
 *
 * Avisos: meses ainda abertos, Balanço que não fecha e contas vencidas. Um condomínio só, obrigatório e do escopo
 * (fora = CondominiumAccessDenied, 404). Ano futuro ou fora de 2000–2100 = 422. Somente leitura.
 */
final class AnnualAccountability
{
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

    /** @return array{0:int, 1:string, 2:string, 3:string} ano, início, fim e a data de corte (fim do ano ou hoje) */
    public static function period(mixed $year, ?string $today = null): array
    {
        $y = AnnualBalance::year($year);
        $today ??= date('Y-m-d');
        if ($y > (int)substr($today, 0, 4)) {
            throw new InvalidArgumentException('O ano ainda não começou.');
        }
        $end = sprintf('%04d-12-31', $y);
        return [$y, sprintf('%04d-01-01', $y), $end, min($end, $today)];
    }

    public function build(User $user, ?int $condominiumId, mixed $year, ?string $today = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$y, $from, $to, $asOf] = self::period($year, $today);
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $accounting = new AccountingReports($this->pdo, $this->scope);
        $income = $accounting->incomeStatement($user, $condominiumId, $from, $to, 'competence');
        $sheet = (new BalanceSheet($this->pdo, $this->scope))->build($user, $condominiumId, $asOf);
        $funds = Accountability::funds($accounting->ledger($user, $condominiumId, $from, $asOf, 'competence', '8'));
        $budget = Accountability::budgetSection((new ErpSettings($this->pdo, $this->scope))->budget($user, $condominiumId, $y), sprintf('%04d-12', $y));
        $delinquency = ($this->delinquency)($user, $condominiumId, $asOf);
        $stmt = $this->pdo->prepare("SELECT competence FROM erp_month_closings WHERE condominium_id = ? AND competence LIKE ? AND status = 'closed'");
        $stmt->execute([$condominiumId, sprintf('%04d-%%', $y)]);
        $closed = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return self::assemble($condominiumId, $y, $from, $to, $asOf, $income, $sheet, $funds, $budget, $delinquency, $closed);
    }

    /** Junta as partes (regra pura). Os meses esperados vão de janeiro até o mês da data de corte. */
    public static function assemble(int $condominiumId, int $year, string $from, string $to, string $asOf, array $income, array $sheet, array $funds,
        array $budget, array $delinquency, array $closedMonths): array
    {
        $expected = [];
        for ($m = 1; $m <= (int)substr($asOf, 5, 2); $m++) {
            $expected[] = sprintf('%04d-%02d', $year, $m);
        }
        $open = array_values(array_diff($expected, $closedMonths));
        $units = array_map(static fn (array $u): array => ['unit_id' => $u['unit_id'], 'unit_label' => (string)$u['unit_label'], 'amount_cents' => (int)$u['amount_cents'],
            'oldest_due_on' => (string)$u['oldest_due_on'], 'max_days_late' => (int)$u['max_days_late']], $delinquency['units'] ?? []);
        usort($units, static fn (array $a, array $b): int => [$b['amount_cents'], $a['unit_label']] <=> [$a['amount_cents'], $b['unit_label']]);
        $delinquent = (int)($delinquency['totals']['amount_cents'] ?? array_sum(array_column($units, 'amount_cents')));
        $assets = array_column($sheet['assets']['rows'] ?? [], 'amount_cents', 'code');
        $budgetRows = array_map(static fn (array $r): array => ['code' => $r['code'], 'name' => $r['name'], 'nature' => $r['nature']] + $r['year_to_date'], $budget['rows'] ?? []);
        $warnings = [];
        if ($open !== []) {
            $warnings[] = ['code' => 'months_open', 'message' => 'Meses ainda abertos: ' . implode(', ', $open) . '. Os números podem mudar.'];
        }
        if ((int)($sheet['diferenca'] ?? 0) !== 0) {
            $warnings[] = ['code' => 'balance_sheet', 'message' => 'O Balanço não fecha na data: confira os saldos iniciais e os lançamentos patrimoniais.'];
        }
        if ($delinquent > 0) {
            $warnings[] = ['code' => 'delinquency', 'message' => 'Há contas vencidas em aberto na data.'];
        }
        return [
            'condominium_id' => $condominiumId, 'year' => $year, 'from' => $from, 'to' => $to, 'as_of' => $asOf,
            'truncated' => count($units) > self::MAX_UNITS || !empty($income['truncated']) || !empty($funds['truncated']),
            'months' => ['expected' => $expected, 'closed' => array_values(array_intersect($expected, $closedMonths)), 'open' => $open],
            'income_statement' => ['rows' => $income['rows'] ?? [], 'totals' => $income['totals'] ?? []],
            'balance_sheet' => ['date' => $sheet['date'] ?? $asOf, 'assets' => $sheet['assets'] ?? [], 'liabilities' => $sheet['liabilities'] ?? [],
                'equity' => $sheet['equity'] ?? [], 'diferenca' => (int)($sheet['diferenca'] ?? 0)],
            'funds' => $funds,
            'budget' => ['rows' => $budgetRows, 'totals' => $budget['totals'] ?? []],
            'delinquency' => ['as_of' => $asOf, 'units' => array_slice($units, 0, self::MAX_UNITS), 'total_cents' => $delinquent],
            'summary' => [
                'revenue_cents' => (int)($income['totals']['revenue_cents'] ?? 0), 'expense_cents' => (int)($income['totals']['expense_cents'] ?? 0),
                'result_cents' => (int)($income['totals']['result_cents'] ?? 0), 'cash_cents' => (int)($assets['7.1'] ?? 0),
                'funds_opening_cents' => (int)$funds['totals']['opening_cents'], 'funds_closing_cents' => (int)$funds['totals']['closing_cents'],
                'delinquent_cents' => $delinquent, 'months_closed' => count($expected) - count($open), 'months_expected' => count($expected),
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * O pacote do ano em uma tabela para CSV (regra pura). Seções: Resumo, DRE, Balanço, Fundos, Orçamento,
     * Inadimplência e Avisos. Texto passa por CollectionReports::csvCell; valores em reais com vírgula.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(array $pack): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $cell = static fn (mixed $v): string => CollectionReports::csvCell((string)$v);
        $s = $pack['summary'] ?? [];
        $rows = [];
        foreach (['Receitas do ano' => 'revenue_cents', 'Despesas do ano' => 'expense_cents', 'Resultado do ano' => 'result_cents',
            'Disponível na data' => 'cash_cents', 'Fundos: saldo no início do ano' => 'funds_opening_cents', 'Fundos: saldo na data' => 'funds_closing_cents',
            'Contas vencidas na data' => 'delinquent_cents'] as $label => $key) {
            $rows[] = ['Resumo', '', $label, '', '', '', $money($s[$key] ?? 0)];
        }
        $rows[] = ['Resumo', '', 'Meses fechados', '', '', '', (int)($s['months_closed'] ?? 0) . ' de ' . (int)($s['months_expected'] ?? 0)];
        foreach ($pack['income_statement']['rows'] ?? [] as $r) {
            $rows[] = ['DRE', $cell($r['code']), $cell(str_repeat('  ', max(0, (int)($r['level'] ?? 1) - 1)) . $r['name']), '', '', '', $money($r['amount_cents'])];
        }
        foreach (['assets' => 'Ativo', 'liabilities' => 'Passivo', 'equity' => 'Patrimônio Líquido'] as $key => $label) {
            foreach ($pack['balance_sheet'][$key]['rows'] ?? [] as $r) {
                $rows[] = ['Balanço: ' . $label, $cell($r['code']), $cell(str_repeat('  ', max(0, (int)($r['level'] ?? 1) - 1)) . $r['name']), '', '', '', $money($r['amount_cents'])];
            }
        }
        $rows[] = ['Balanço', '', 'Diferença', '', '', '', $money($pack['balance_sheet']['diferenca'] ?? 0)];
        foreach ($pack['funds']['accounts'] ?? [] as $f) {
            $rows[] = ['Fundos', $cell($f['code']), $cell($f['name']), $money($f['opening_cents']), $money($f['in_cents']), $money($f['out_cents']), $money($f['closing_cents'])];
        }
        foreach ($pack['budget']['rows'] ?? [] as $b) {
            $rows[] = ['Orçamento (ano)', $cell($b['code']), $cell($b['name']), $money($b['budget_cents']), $money($b['actual_cents']), '', $money($b['difference_cents'])];
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
        return ['header' => ['Seção', 'Código', 'Item', 'Saldo anterior ou previsto', 'Entradas ou lançado', 'Saídas', 'Valor ou saldo final'], 'rows' => $rows];
    }
}
