<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Simulação da taxa condominial a partir da previsão orçamentária (ADR 0189), somente leitura, para a assembleia
 * decidir a taxa depois da previsão (AP-163, ADR 0187):
 *
 * - A necessidade do mês é a despesa prevista no ano ÷ 12, mais o fundo de reserva (% sobre ela, 0 a 30) e uma margem
 *   para a inadimplência (a cobrança é dividida por 1 − %, 0 a 50). As outras receitas previstas não abatem nada: a
 *   taxa cobre a despesa toda (hipótese conservadora).
 * - A taxa total do mês é rateada pela fração ideal (split=fraction, o padrão; sem fração em alguma unidade ativa, o
 *   rateio é bloqueado como no AP-062) ou em partes iguais (split=equal), com o centavo que sobra pelo maior resto
 *   (Invoices::apportion).
 * - Cada unidade traz o que foi cobrado dela no último mês completo (contas a receber válidas da unidade, por
 *   competência; pode incluir água, gás e outras cobranças) e a variação.
 * - Inadimplência observada, como sugestão: contas a receber com vencimento nos 12 meses completos anteriores, vencido
 *   em aberto hoje ÷ faturado (ChartReports::rate). Só é sugerida com faturamento em ao menos 3 desses meses: com
 *   histórico curto vem null e um aviso.
 *
 * Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class FeeSimulation
{
    public const SPLITS = ['fraction', 'equal'];
    public const MIN_HISTORY_MONTHS = 3;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Taxa total do mês em centavos (regra pura). */
    public static function monthlyTotal(int $annualExpenseCents, float $reservePercent, float $delinquencyPercent): int
    {
        $base = $annualExpenseCents / 12;
        return (int)round($base * (1 + $reservePercent / 100) / (1 - $delinquencyPercent / 100));
    }

    public function build(User $user, ?int $condominiumId, mixed $year, mixed $reserve = 0, mixed $delinquency = 0, mixed $split = 'fraction', ?string $today = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today ??= date('Y-m-d');
        $y = filter_var($year, FILTER_VALIDATE_INT, ['options' => ['min_range' => 2000, 'max_range' => (int)substr($today, 0, 4) + 5]]);
        if ($y === false || $y === null) {
            throw new InvalidArgumentException('Informe o ano (até 5 anos à frente).');
        }
        $r = self::percent($reserve, 30, 'Fundo de reserva de 0% a 30%.');
        $d = self::percent($delinquency, 50, 'Margem de inadimplência de 0% a 50%.');
        $split = $split === null || $split === '' ? 'fraction' : (string)$split;
        if (!in_array($split, self::SPLITS, true)) {
            throw new InvalidArgumentException('Rateio: fraction ou equal.');
        }
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar

        $budget = $this->pdo->prepare("SELECT a.nature, COUNT(DISTINCT b.month) AS months, SUM(b.amount_cents) AS cents FROM erp_budget_lines b
            JOIN erp_chart_accounts a ON a.id = b.chart_account_id WHERE b.condominium_id = :c AND b.year = :y GROUP BY a.nature");
        $budget->execute(['c' => $condominiumId, 'y' => (int)$y]);
        $natures = [];
        foreach ($budget->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $natures[(string)$row['nature']] = ['months' => (int)$row['months'], 'cents' => (int)$row['cents']];
        }
        $expense = $natures['despesa']['cents'] ?? 0;
        $lastMonth = (new \DateTimeImmutable(substr($today, 0, 7) . '-01'))->modify('-1 month')->format('Y-m');
        $observed = $this->observedDelinquency($condominiumId, $today);
        $warnings = [];
        if ($observed['percent'] === null) {
            $warnings[] = ['code' => 'short_history', 'message' => sprintf('Faturamento em só %d dos 12 meses anteriores: a inadimplência observada não é sugerida.', $observed['months'])];
        }
        $result = ['condominium_id' => $condominiumId, 'year' => (int)$y, 'split' => $split, 'reserve_percent' => $r, 'delinquency_percent' => $d,
            'budget_expense_cents' => $expense, 'budget_revenue_cents' => $natures['receita']['cents'] ?? 0, 'budget_expense_months' => $natures['despesa']['months'] ?? 0,
            'observed_delinquency' => $observed, 'compared_competence' => $lastMonth, 'missing_fraction' => [], 'rows' => []];
        if ($expense <= 0) {
            return $result + ['blocked' => true, 'message' => "Não há despesa prevista em {$y}: faça a previsão orçamentária primeiro.", 'warnings' => $warnings];
        }
        if ($result['budget_expense_months'] < 12) {
            $warnings[] = ['code' => 'partial_budget', 'message' => sprintf('A previsão de despesa de %d tem só %d mês(es) preenchido(s).', $y, $result['budget_expense_months'])];
        }
        $units = $this->pdo->prepare("SELECT id, label, ideal_fraction FROM erp_units WHERE condominium_id = :c AND deleted_at IS NULL AND status = 'active' ORDER BY label, id");
        $units->execute(['c' => $condominiumId]);
        $units = $units->fetchAll(PDO::FETCH_ASSOC);
        $result['missing_fraction'] = $split === 'fraction' ? array_values(array_map(static fn (array $u): string => (string)$u['label'],
            array_filter($units, static fn (array $u): bool => $u['ideal_fraction'] === null || (float)$u['ideal_fraction'] <= 0))) : [];
        $monthly = self::monthlyTotal($expense, $r, $d);
        $result += ['monthly_total_cents' => $monthly, 'reserve_cents' => (int)round($expense / 12 * $r / 100)];
        if ($units === [] || $result['missing_fraction'] !== []) {
            return $result + ['blocked' => true, 'message' => $units === [] ? 'O condomínio não tem unidades ativas.' : 'Há unidades sem fração ideal: use o rateio em partes iguais ou cadastre as frações.',
                'warnings' => $warnings];
        }
        $fractions = [];
        foreach ($units as $u) {
            $fractions[(int)$u['id']] = $split === 'equal' ? 1 : (string)$u['ideal_fraction'];
        }
        $shares = Invoices::apportion($monthly, $fractions);
        $billed = $this->pdo->prepare("SELECT unit_id, SUM(amount_cents) FROM erp_entries WHERE condominium_id = :c AND nature = 'receita' AND unit_id IS NOT NULL
            AND status = 'active' AND deleted_at IS NULL AND competence = :m GROUP BY unit_id");
        $billed->execute(['c' => $condominiumId, 'm' => $lastMonth]);
        $current = array_map('intval', $billed->fetchAll(PDO::FETCH_KEY_PAIR));
        foreach ($units as $u) {
            $id = (int)$u['id'];
            $before = $current[$id] ?? null;
            $result['rows'][] = ['unit_id' => $id, 'unit_label' => (string)$u['label'], 'ideal_fraction' => $u['ideal_fraction'] === null ? null : (string)$u['ideal_fraction'],
                'fee_cents' => $shares[$id], 'billed_last_month_cents' => $before,
                'variation_percent' => $before !== null && $before > 0 ? round(($shares[$id] - $before) * 100 / $before, 2) : null];
        }
        if ($current === []) {
            $warnings[] = ['code' => 'no_billing_last_month', 'message' => "Nada cobrado das unidades em {$lastMonth}: sem comparação."];
        }
        return $result + ['blocked' => false, 'billed_last_month_cents' => array_sum($current), 'warnings' => $warnings];
    }

    /** @return array{from:string, to:string, months:int, billed_cents:int, overdue_cents:int, percent:?float} */
    private function observedDelinquency(int $condominiumId, string $today): array
    {
        $month = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        $from = $month->modify('-12 months')->format('Y-m-d');
        $to = $month->modify('-1 day')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT COUNT(DISTINCT SUBSTR(due_on, 1, 7)) AS months, COALESCE(SUM(amount_cents), 0) AS billed,
                COALESCE(SUM(CASE WHEN paid_on IS NULL THEN amount_cents ELSE 0 END), 0) AS overdue
            FROM erp_entries WHERE condominium_id = :c AND nature = 'receita' AND status = 'active' AND deleted_at IS NULL AND due_on BETWEEN :f AND :t");
        $stmt->execute(['c' => $condominiumId, 'f' => $from, 't' => $to]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: ['months' => 0, 'billed' => 0, 'overdue' => 0];
        $months = (int)$row['months'];
        return ['from' => $from, 'to' => $to, 'months' => $months, 'billed_cents' => (int)$row['billed'], 'overdue_cents' => (int)$row['overdue'],
            'percent' => $months >= self::MIN_HISTORY_MONTHS ? ChartReports::rate((int)$row['overdue'], (int)$row['billed']) : null];
    }

    private static function percent(mixed $value, int $max, string $message): float
    {
        if ($value !== null && !is_scalar($value)) {
            throw new InvalidArgumentException($message);
        }
        $v = $value === null || $value === '' ? 0.0 : filter_var(str_replace(',', '.', (string)$value), FILTER_VALIDATE_FLOAT);
        if ($v === false || $v < 0 || $v > $max) {
            throw new InvalidArgumentException($message);
        }
        return round((float)$v, 2);
    }
}
