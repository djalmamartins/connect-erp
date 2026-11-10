<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Balancete Anual (AP-080, ADR 0164), somente leitura e calculado dos lançamentos válidos (sem tabela
 * própria): por conta do plano de contas, o valor de cada mês do ano e o total, com as analíticas somadas
 * nas sintéticas pelo código (1.01.02 → 1.01 → 1), como o balancete de verificação (AP-079).
 *
 * - basis=competence (padrão): soma pela competência do lançamento (previsto, pago ou não);
 * - basis=cash: soma pelo mês do pagamento (realizado).
 *
 * Escopo (ADR-002): um condomínio (fora do escopo = CondominiumAccessDenied, 404) ou todos os do escopo
 * (null). A consulta agrupada tem LIMIT (MAX_GROUPS); passando dele, o resultado vem com truncated = true.
 */
final class AnnualBalance
{
    public const BASES = ['competence', 'cash'];
    public const MAX_GROUPS = 6000;
    public const MONTH_LABELS = ['Jan', 'Fev', 'Mar', 'Abr', 'Mai', 'Jun', 'Jul', 'Ago', 'Set', 'Out', 'Nov', 'Dez'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Ano aceito (regra pura): 2000 a 2100. */
    public static function year(mixed $year): int
    {
        $int = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => 2100]]);
        if ($int === false || $int === null) {
            throw new InvalidArgumentException('Informe o ano (AAAA, de 2000 a 2100).');
        }
        return (int)$int;
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
     * Monta o balancete (regra pura). Entrada: uma linha por conta analítica e mês (AAAA-MM) com o valor.
     *
     * @param list<array{code:string, name:string, nature:string, month:string, cents:int|string}> $rows
     * @param array<string, string> $names nome das sintéticas por código
     * @return array{months:list<string>, rows:list<array>, totals:array}
     */
    public static function build(array $rows, int $year, array $names = []): array
    {
        $months = array_map(static fn (int $m): string => sprintf('%04d-%02d', $year, $m), range(1, 12));
        $index = array_flip($months);
        $tree = [];
        $totals = ['revenue' => array_fill(0, 12, 0), 'expense' => array_fill(0, 12, 0)];
        foreach ($rows as $row) {
            $slot = $index[(string)$row['month']] ?? null;
            $nature = (string)$row['nature'];
            if ($slot === null || !in_array($nature, ['receita', 'despesa'], true)) {
                continue;   // mês fora do ano ou natureza desconhecida não entra
            }
            $cents = (int)$row['cents'];
            $totals[$nature === 'receita' ? 'revenue' : 'expense'][$slot] += $cents;
            $parts = explode('.', (string)$row['code']);
            for ($depth = 1; $depth <= count($parts); $depth++) {
                $code = implode('.', array_slice($parts, 0, $depth));
                $analytic = $depth === count($parts);
                $key = $nature . '|' . $code;
                $tree[$key] ??= ['code' => $code, 'name' => $analytic ? (string)$row['name'] : ($names[$code] ?? $code), 'nature' => $nature,
                    'level' => $depth, 'analytic' => $analytic, 'months_cents' => array_fill(0, 12, 0), 'total_cents' => 0];
                $tree[$key]['months_cents'][$slot] += $cents;
                $tree[$key]['total_cents'] += $cents;
            }
        }
        uasort($tree, static fn (array $a, array $b): int => [$a['nature'] === 'receita' ? 0 : 1, self::sortable($a['code'])]
            <=> [$b['nature'] === 'receita' ? 0 : 1, self::sortable($b['code'])]);
        $result = array_map(static fn (int $r, int $e): int => $r - $e, $totals['revenue'], $totals['expense']);
        return ['months' => $months, 'rows' => array_values($tree), 'totals' => [
            'revenue_months_cents' => $totals['revenue'], 'expense_months_cents' => $totals['expense'], 'result_months_cents' => $result,
            'revenue_cents' => array_sum($totals['revenue']), 'expense_cents' => array_sum($totals['expense']), 'result_cents' => array_sum($result),
        ]];
    }

    /**
     * Tabela para exportar em CSV (regra pura), com as células saneadas por CollectionReports::csvCell.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(array $report): array
    {
        $money = static fn (int $c): string => number_format($c / 100, 2, ',', '');
        $cell = [CollectionReports::class, 'csvCell'];
        $header = array_merge(['Código', 'Conta', 'Natureza'], self::MONTH_LABELS, ['Total']);
        $rows = [];
        foreach ($report['rows'] as $row) {
            $rows[] = array_merge([$cell((string)$row['code']), $cell(str_repeat('  ', (int)$row['level'] - 1) . $row['name']), $cell($row['nature'] === 'receita' ? 'Receita' : 'Despesa')],
                array_map($money, $row['months_cents']), [$money((int)$row['total_cents'])]);
        }
        $t = $report['totals'];
        foreach ([['Total de receitas', 'revenue'], ['Total de despesas', 'expense'], ['Resultado', 'result']] as [$label, $key]) {
            $rows[] = array_merge(['', $label, ''], array_map($money, $t["{$key}_months_cents"]), [$money((int)$t["{$key}_cents"])]);
        }
        return ['header' => $header, 'rows' => $rows];
    }

    /** @return array{condominium_id:?int, year:int, basis:string, truncated:bool, months:list<string>, rows:list<array>, totals:array} */
    public function report(User $user, ?int $condominiumId, mixed $year, mixed $basis = 'competence'): array
    {
        $year = self::year($year);
        $basis = self::basis($basis);
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId, 'year' => $year, 'basis' => $basis];
        if ($ids === []) {
            return $base + ['truncated' => false] + self::build([], $year);
        }
        $in = implode(',', $ids);
        [$month, $filter, $params] = $basis === 'cash'
            ? ['SUBSTR(e.paid_on, 1, 7)', 'e.paid_on IS NOT NULL AND e.paid_on BETWEEN :f AND :t', ['f' => "{$year}-01-01", 't' => "{$year}-12-31"]]
            : ['e.competence', 'e.competence BETWEEN :f AND :t', ['f' => "{$year}-01", 't' => "{$year}-12"]];
        $stmt = $this->pdo->prepare("SELECT a.code, a.name, e.nature, {$month} AS month, SUM(e.amount_cents) AS cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$in}) AND e.deleted_at IS NULL AND e.status = 'active' AND {$filter}
            GROUP BY a.code, a.name, e.nature, {$month} ORDER BY a.code LIMIT " . (self::MAX_GROUPS + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > self::MAX_GROUPS;
        return $base + ['truncated' => $truncated] + self::build(array_slice($rows, 0, self::MAX_GROUPS), $year, $this->syntheticNames($ids));
    }

    /** "1.10" depois de "1.9": compara por número em cada nível. */
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
            $names[(string)$row['code']] ??= (string)$row['name'];   // a própria do condomínio vem antes do padrão
        }
        return $names;
    }
}
