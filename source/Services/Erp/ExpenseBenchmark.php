<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Comparativo de despesas entre condomínios (ADR 0201), somente leitura, para a administradora que cuida de vários
 * condomínios: em cada conta do plano padrão (comum a todos, então comparável), quanto cada condomínio do escopo gasta
 * por unidade por mês, a mediana entre eles e quem está muito acima (limpeza a R$ 50 por unidade quando a mediana é
 * R$ 30, elevador o dobro dos outros).
 *
 * - Despesas válidas (ativas, não excluídas) por competência, na faixa (ManagementReports::competences, até 12 meses
 *   aqui), só nas contas analíticas do padrão (condominium_id nulo): conta própria de um condomínio não tem par.
 * - Por unidade por mês (regra pura perUnitMonth): o total da conta ÷ unidades ativas ÷ meses do condomínio com despesa
 *   na faixa (condomínio novo não fica "barato" por ter poucos meses).
 * - Mediana (regra pura median) entre os condomínios com gasto na conta e histórico de pelo menos MIN_MONTHS meses;
 *   acima da mediana mais que a tolerância (padrão 50%) vira outlier, se a conta tem pelo menos MIN_PEERS condomínios
 *   comparáveis. Condomínio com histórico curto aparece, marcado, fora da mediana.
 * - Condomínio sem unidade ativa fica de fora (não há por unidade), com o aviso no_units.
 *
 * Só nomes de condomínio e valores: nenhum dado de unidade, morador ou fornecedor. Condomínios do escopo do usuário;
 * com menos de 2 comparáveis vem o aviso not_enough_condominiums.
 */
final class ExpenseBenchmark
{
    public const MAX_MONTHS = 12;
    public const MIN_MONTHS = 3;
    public const MIN_PEERS = 3;
    public const DEFAULT_TOLERANCE = 50;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Mediana em centavos (regra pura); null sem valores. */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $n = count($values);
        $mid = intdiv($n, 2);
        return $n % 2 === 1 ? (int)$values[$mid] : (int)round(($values[$mid - 1] + $values[$mid]) / 2);
    }

    /** Centavos por unidade por mês (regra pura). */
    public static function perUnitMonth(int $totalCents, int $units, int $months): int
    {
        return $units > 0 && $months > 0 ? (int)round($totalCents / $units / $months) : 0;
    }

    public function build(User $user, mixed $from, mixed $to, mixed $tolerance = null): array
    {
        [$from, $to] = ManagementReports::competences(is_string($from) ? $from : '', is_string($to) ? $to : '');
        $span = ((int)substr($to, 0, 4) - (int)substr($from, 0, 4)) * 12 + (int)substr($to, 5, 2) - (int)substr($from, 5, 2) + 1;
        if ($span > self::MAX_MONTHS) {
            throw new InvalidArgumentException(sprintf('No máximo %d competências.', self::MAX_MONTHS));
        }
        $tol = $tolerance === null || $tolerance === '' ? self::DEFAULT_TOLERANCE
            : (is_int($tolerance) || is_string($tolerance) ? filter_var($tolerance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 10, 'max_range' => 500]]) : false);
        if ($tol === false) {
            throw new InvalidArgumentException('Tolerância de 10% a 500%.');
        }
        $ids = $this->scope->allowedIds($user);
        $result = ['from' => $from, 'to' => $to, 'tolerance_percent' => $tol, 'condominiums' => [], 'accounts' => [], 'outliers' => [], 'warnings' => []];
        if ($ids === []) {
            $result['warnings'][] = ['code' => 'not_enough_condominiums', 'message' => 'Nenhum condomínio no seu escopo.'];
            return $result;
        }
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT c.id, c.name, (SELECT COUNT(*) FROM erp_units u WHERE u.condominium_id = c.id AND u.status = 'active' AND u.deleted_at IS NULL) AS units
            FROM condominiums c WHERE c.id IN ({$marks}) ORDER BY c.name, c.id");
        $stmt->execute($ids);
        $condos = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $condos[(int)$r['id']] = ['condominium_id' => (int)$r['id'], 'name' => (string)$r['name'], 'units' => (int)$r['units'], 'months' => 0];
        }
        $months = $this->pdo->prepare("SELECT condominium_id, COUNT(DISTINCT competence) FROM erp_entries
            WHERE condominium_id IN ({$marks}) AND nature = 'despesa' AND status = 'active' AND deleted_at IS NULL AND competence BETWEEN ? AND ? GROUP BY condominium_id");
        $months->execute(array_merge($ids, [$from, $to]));
        foreach ($months->fetchAll(PDO::FETCH_KEY_PAIR) as $id => $count) {
            $condos[(int)$id]['months'] = (int)$count;
        }
        $noUnits = [];
        foreach ($condos as $id => $c) {
            if ($c['units'] === 0) {
                $noUnits[] = $c['name'];
                unset($condos[$id]);
            }
        }
        if ($noUnits !== []) {
            $result['warnings'][] = ['code' => 'no_units', 'message' => 'Sem unidade ativa, fora do comparativo: ' . implode(', ', $noUnits) . '.'];
        }
        foreach ($condos as $id => $c) {
            $condos[$id]['short_history'] = $c['months'] < self::MIN_MONTHS;
        }
        $result['condominiums'] = array_values($condos);
        $comparable = array_filter($condos, static fn (array $c): bool => !$c['short_history']);
        if (count($comparable) < 2) {
            $result['warnings'][] = ['code' => 'not_enough_condominiums', 'message' => 'Menos de 2 condomínios com histórico para comparar.'];
        }
        if ($condos === []) {
            return $result;
        }

        $keep = array_keys($condos);
        $marks = implode(',', array_fill(0, count($keep), '?'));
        $stmt = $this->pdo->prepare("SELECT e.condominium_id, a.id AS account_id, a.code, a.name, SUM(e.amount_cents) AS cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id IN ({$marks}) AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN ? AND ?
              AND a.condominium_id IS NULL AND a.kind = 'analitica'
            GROUP BY e.condominium_id, a.id, a.code, a.name ORDER BY a.code, a.id");
        $stmt->execute(array_merge($keep, [$from, $to]));
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $aid = (int)$r['account_id'];
            $c = $condos[(int)$r['condominium_id']];
            $accounts[$aid] ??= ['chart_account_id' => $aid, 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'items' => []];
            $accounts[$aid]['items'][] = ['condominium_id' => $c['condominium_id'], 'name' => $c['name'], 'units' => $c['units'], 'months' => $c['months'],
                'short_history' => $c['short_history'], 'total_cents' => (int)$r['cents'],
                'per_unit_month_cents' => self::perUnitMonth((int)$r['cents'], $c['units'], $c['months'])];
        }
        foreach ($accounts as $aid => $account) {
            $values = array_column(array_filter($account['items'], static fn (array $i): bool => !$i['short_history']), 'per_unit_month_cents');
            $median = self::median($values);
            $peers = count($values);
            foreach ($account['items'] as $k => $item) {
                $vs = $median !== null && $median > 0 ? round(($item['per_unit_month_cents'] - $median) * 100 / $median, 2) : null;
                $account['items'][$k]['vs_median_percent'] = $vs;
                if ($peers >= self::MIN_PEERS && !$item['short_history'] && $vs !== null && $vs > $tol) {
                    $result['outliers'][] = ['chart_account_id' => $aid, 'code' => $account['code'], 'account' => $account['name'],
                        'condominium_id' => $item['condominium_id'], 'name' => $item['name'], 'per_unit_month_cents' => $item['per_unit_month_cents'],
                        'median_cents' => $median, 'vs_median_percent' => $vs];
                }
            }
            usort($account['items'], static fn (array $a, array $b): int => [$b['per_unit_month_cents'], $a['name']] <=> [$a['per_unit_month_cents'], $b['name']]);
            $result['accounts'][] = $account + ['median_per_unit_month_cents' => $median, 'comparable_condominiums' => $peers];
        }
        usort($result['outliers'], static fn (array $a, array $b): int => [$b['vs_median_percent'], $a['code']] <=> [$a['vs_median_percent'], $b['code']]);
        return $result;
    }
}
