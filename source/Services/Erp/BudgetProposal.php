<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Proposta de previsão orçamentária (ADR 0187), para preparar a assembleia: a sugestão do orçamento de um ano (AP-163)
 * a partir do realizado nos últimos 12 meses completos, com um reajuste.
 *
 * - suggest(): por conta analítica ativa (do padrão ou do condomínio) com lançamento no período-base (por competência,
 *   só lançamentos válidos), o total realizado e a sugestão mês a mês para o ano escolhido, com o reajuste (−50% a
 *   +100%). Distribuição flat = o total reajustado dividido em 12 (a sobra dos centavos vai para dezembro); seasonal =
 *   cada mês segue o mesmo mês do período-base, reajustado. Mostra também o previsto que já existe no ano e os totais
 *   por natureza. Avisa quando a despesa sugerida passa da receita, com o reajuste de receita que zeraria o déficit.
 * - apply(): grava a sugestão pelo ErpSettings::setBudget (mesma validação, trava de competência e auditoria), conta a
 *   conta, todas ou só as escolhidas. Tudo é conferido antes de gravar (ano sem mês fechado, contas da sugestão), para
 *   não ficar pela metade.
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class BudgetProposal
{
    public const DISTRIBUTIONS = ['flat', 'seasonal'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Sugestão mês a mês (regra pura): 12 valores em centavos, nunca negativos.
     *
     * @param array<int, int> $baseMonths realizado por mês do calendário (1 a 12)
     * @return array<int, int>
     */
    public static function distribute(array $baseMonths, float $factor, string $distribution): array
    {
        $out = [];
        if ($distribution === 'seasonal') {
            for ($m = 1; $m <= 12; $m++) {
                $out[$m] = max(0, (int)round(($baseMonths[$m] ?? 0) * $factor));
            }
            return $out;
        }
        $total = max(0, (int)round(array_sum($baseMonths) * $factor));   // estorno não vira previsto negativo
        $each = intdiv($total, 12);
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = $each;
        }
        $out[12] += $total - $each * 12;   // centavos da divisão: o total bate
        return $out;
    }

    /** @return array{0:string, 1:string} competências AAAA-MM do período-base: os 12 meses completos antes de hoje (regra pura) */
    public static function basePeriod(string $today): array
    {
        $month = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        return [$month->modify('-12 months')->format('Y-m'), $month->modify('-1 month')->format('Y-m')];
    }

    public function suggest(User $user, ?int $condominiumId, mixed $year, mixed $adjustment = 0, mixed $distribution = 'flat', ?string $today = null): array
    {
        [$condominium, $y, $factor, $dist, $today] = $this->input($user, $condominiumId, $year, $adjustment, $distribution, $today);
        [$from, $to] = self::basePeriod($today);
        $stmt = $this->pdo->prepare("SELECT a.id, a.code, a.name, a.nature, e.competence, SUM(e.amount_cents) AS cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id = ? AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN ? AND ?
              AND a.kind = 'analitica' AND a.status = 'active' AND a.deleted_at IS NULL AND (a.condominium_id IS NULL OR a.condominium_id = ?)
            GROUP BY a.id, a.code, a.name, a.nature, e.competence ORDER BY a.code, a.id");
        $stmt->execute([$condominium, $from, $to, $condominium]);
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $id = (int)$r['id'];
            $accounts[$id] ??= ['chart_account_id' => $id, 'code' => (string)$r['code'], 'name' => (string)$r['name'], 'nature' => (string)$r['nature'],
                'base_months' => array_fill(1, 12, 0)];
            $accounts[$id]['base_months'][(int)substr((string)$r['competence'], 5, 2)] += (int)$r['cents'];
        }
        $current = $this->pdo->prepare('SELECT chart_account_id, SUM(amount_cents) FROM erp_budget_lines WHERE condominium_id = ? AND year = ? GROUP BY chart_account_id');
        $current->execute([$condominium, $y]);
        $existing = array_map('intval', $current->fetchAll(PDO::FETCH_KEY_PAIR));
        $rows = [];
        $totals = ['receita' => ['base_cents' => 0, 'suggested_cents' => 0, 'current_budget_cents' => 0],
            'despesa' => ['base_cents' => 0, 'suggested_cents' => 0, 'current_budget_cents' => 0]];
        foreach ($accounts as $a) {
            $months = self::distribute($a['base_months'], $factor, $dist);
            $row = $a + ['base_cents' => array_sum($a['base_months']), 'suggested_months' => $months, 'suggested_cents' => array_sum($months),
                'current_budget_cents' => $existing[$a['chart_account_id']] ?? 0];
            $rows[] = $row;
            if (isset($totals[$a['nature']])) {
                foreach (['base_cents', 'suggested_cents', 'current_budget_cents'] as $k) {
                    $totals[$a['nature']][$k] += $row[$k];
                }
            }
        }
        $revenue = $totals['receita']['suggested_cents'];
        $expense = $totals['despesa']['suggested_cents'];
        $warnings = [];
        if ($expense > $revenue) {
            $needed = $revenue > 0 ? round(($expense - $revenue) * 100 / $revenue, 2) : null;
            $warnings[] = ['code' => 'deficit', 'message' => 'A despesa sugerida passa da receita'
                . ($needed !== null ? ': a receita precisaria subir ' . number_format($needed, 2, ',', '') . '%.' : '.'), 'revenue_increase_percent' => $needed];
        }
        return ['condominium_id' => $condominium, 'year' => $y, 'base_from' => $from, 'base_to' => $to, 'adjustment_percent' => round(($factor - 1) * 100, 2),
            'distribution' => $dist, 'rows' => $rows, 'totals' => $totals + ['result_cents' => $revenue - $expense], 'warnings' => $warnings];
    }

    /** @param list<mixed>|null $accountIds só estas contas (null = todas da sugestão) */
    public function apply(User $user, ?int $condominiumId, mixed $year, mixed $adjustment = 0, mixed $distribution = 'flat', ?array $accountIds = null, ?string $today = null): array
    {
        $proposal = $this->suggest($user, $condominiumId, $year, $adjustment, $distribution, $today);
        $condominium = (int)$proposal['condominium_id'];
        $y = (int)$proposal['year'];
        $rows = $proposal['rows'];
        if ($accountIds !== null) {
            $wanted = [];
            foreach ($accountIds as $id) {
                $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($id === false) {
                    throw new InvalidArgumentException('Contas: informe a lista de ids.');
                }
                $wanted[$id] = true;
            }
            $rows = array_values(array_filter($rows, static fn (array $r): bool => isset($wanted[$r['chart_account_id']])));
            if ($wanted === [] || count($rows) !== count($wanted)) {
                throw new InvalidArgumentException('Escolha contas que estão na sugestão.');
            }
        }
        if ($rows === []) {
            throw new InvalidArgumentException('Não há realizado no período-base para sugerir.');
        }
        $registry = new Registry\RegistryService($this->pdo, $this->scope);
        for ($m = 1; $m <= 12; $m++) {
            if ($registry->isClosed($condominium, sprintf('%04d-%02d', $y, $m))) {
                throw new DomainException("O ano {$y} já tem mês fechado: ajuste o previsto à mão, conta a conta.");
            }
        }
        $settings = new ErpSettings($this->pdo, $this->scope);
        $applied = [];
        foreach ($rows as $row) {
            $settings->setBudget($user, $condominium, $y, $row['chart_account_id'], array_values($row['suggested_months']));
            $applied[] = $row['chart_account_id'];
        }
        return ['condominium_id' => $condominium, 'year' => $y, 'applied_accounts' => $applied, 'budget' => $settings->budget($user, $condominium, $y)];
    }

    /** @return array{0:int, 1:int, 2:float, 3:string, 4:string} */
    private function input(User $user, ?int $condominiumId, mixed $year, mixed $adjustment, mixed $distribution, ?string $today): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today ??= date('Y-m-d');
        $y = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => (int)substr($today, 0, 4) + 5]]);
        if ($y === false || $y === null) {
            throw new InvalidArgumentException('Informe o ano (até 5 anos à frente).');
        }
        $adj = $adjustment === null || $adjustment === '' ? 0.0 : filter_var(str_replace(',', '.', (string)$adjustment), FILTER_VALIDATE_FLOAT);
        if ($adj === false || $adj < -50 || $adj > 100) {
            throw new InvalidArgumentException('Reajuste de −50% a +100%.');
        }
        $dist = $distribution === null || $distribution === '' ? 'flat' : (string)$distribution;
        if (!in_array($dist, self::DISTRIBUTIONS, true)) {
            throw new InvalidArgumentException('Distribuição: flat ou seasonal.');
        }
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        return [$condominiumId, (int)$y, 1 + round((float)$adj, 2) / 100, $dist, $today];
    }
}
