<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência do faturamento (ADR 0198), somente leitura: antes de mandar as cobranças do mês, a administradora confere
 * se cada unidade foi cobrada certo na competência. Contas a receber válidas (ativas, não excluídas) do condomínio:
 *
 * - missing: unidade ativa sem nenhuma conta a receber na competência (esqueceram de cobrar);
 * - duplicates: unidade com duas ou mais contas a receber na mesma conta do plano na competência (fatura + lançamento
 *   avulso da mesma taxa, por exemplo);
 * - variations: unidade cujo total na competência mudou mais que a tolerância (%) em relação à competência anterior; só
 *   quando as duas têm valor (sem histórico não há comparação, e o aviso no_previous diz isso);
 * - inactive_units: conta a receber de unidade inativa ou excluída;
 * - without_unit: receita da competência sem unidade (pode ser certo, como rendimento ou multa avulsa, mas vale olhar).
 *
 * Resumo com unidades ativas, cobradas, totais da competência e da anterior. Cada lista vem limitada a LIMIT, com a marca
 * de corte. Das unidades só sai o rótulo (101, Bloco A 12): nenhum dado de morador. Um condomínio só, do escopo (fora =
 * CondominiumAccessDenied, 404).
 */
final class BillingCheck
{
    public const LIMIT = 200;
    public const DEFAULT_TOLERANCE = 20;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Variação em % (regra pura): null sem valor anterior; arredondada em 2 casas. */
    public static function variation(int $previous, int $current): ?float
    {
        return $previous > 0 ? round(($current - $previous) * 100 / $previous, 2) : null;
    }

    public function build(User $user, ?int $condominiumId, mixed $competence, mixed $tolerance = null): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        if (!is_string($competence) || !RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM).');
        }
        $tol = $tolerance === null || $tolerance === '' ? self::DEFAULT_TOLERANCE
            : (is_int($tolerance) || is_string($tolerance) ? filter_var($tolerance, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]) : false);
        if ($tol === false) {
            throw new InvalidArgumentException('Tolerância de 1% a 500%.');
        }
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $previous = (new \DateTimeImmutable($competence . '-01'))->modify('-1 month')->format('Y-m');

        $units = $this->pdo->prepare('SELECT id, label, status, deleted_at FROM erp_units WHERE condominium_id = ? ORDER BY label, id');
        $units->execute([$condominiumId]);
        $all = [];
        foreach ($units->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $all[(int)$u['id']] = ['label' => (string)$u['label'], 'active' => $u['status'] === 'active' && $u['deleted_at'] === null];
        }
        $stmt = $this->pdo->prepare("SELECT e.id, e.unit_id, e.competence, e.amount_cents, e.chart_account_id, a.code, a.name AS account_name
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id = ? AND e.nature = 'receita' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence IN (?, ?)
            ORDER BY e.id");
        $stmt->execute([$condominiumId, $competence, $previous]);
        $current = [];
        $before = [];
        $byAccount = [];
        $withoutUnit = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $cents = (int)$r['amount_cents'];
            if ($r['unit_id'] === null) {
                if ($r['competence'] === $competence) {
                    $withoutUnit[] = ['entry_id' => (int)$r['id'], 'amount_cents' => $cents, 'account' => $r['code'] . ' ' . $r['account_name']];
                }
                continue;
            }
            $unit = (int)$r['unit_id'];
            if ($r['competence'] === $previous) {
                $before[$unit] = ($before[$unit] ?? 0) + $cents;
                continue;
            }
            $current[$unit] = ($current[$unit] ?? 0) + $cents;
            $key = $unit . ':' . $r['chart_account_id'];
            $byAccount[$key] ??= ['unit_id' => $unit, 'chart_account_id' => (int)$r['chart_account_id'], 'account' => $r['code'] . ' ' . $r['account_name'],
                'entry_ids' => [], 'total_cents' => 0];
            $byAccount[$key]['entry_ids'][] = (int)$r['id'];
            $byAccount[$key]['total_cents'] += $cents;
        }
        $label = static fn (int $id): string => $all[$id]['label'] ?? ('#' . $id);
        $sections = ['missing' => [], 'duplicates' => [], 'variations' => [], 'inactive_units' => [], 'without_unit' => $withoutUnit];
        foreach ($all as $id => $u) {
            if ($u['active'] && !isset($current[$id])) {
                $sections['missing'][] = ['unit_id' => $id, 'unit_label' => $u['label'], 'previous_cents' => $before[$id] ?? null];
            }
        }
        foreach ($byAccount as $group) {
            if (count($group['entry_ids']) > 1) {
                $sections['duplicates'][] = ['unit_id' => $group['unit_id'], 'unit_label' => $label($group['unit_id'])] + $group;
            }
        }
        foreach ($current as $id => $cents) {
            if (!($all[$id]['active'] ?? false)) {
                $sections['inactive_units'][] = ['unit_id' => $id, 'unit_label' => $label($id), 'amount_cents' => $cents];
            }
            $variation = self::variation($before[$id] ?? 0, $cents);
            if ($variation !== null && abs($variation) > $tol) {
                $sections['variations'][] = ['unit_id' => $id, 'unit_label' => $label($id), 'previous_cents' => $before[$id], 'current_cents' => $cents,
                    'variation_percent' => $variation];
            }
        }
        usort($sections['variations'], static fn (array $a, array $b): int => [abs($b['variation_percent']), $a['unit_label']] <=> [abs($a['variation_percent']), $b['unit_label']]);
        usort($sections['inactive_units'], static fn (array $a, array $b): int => [$a['unit_label'], $a['unit_id']] <=> [$b['unit_label'], $b['unit_id']]);
        usort($sections['duplicates'], static fn (array $a, array $b): int => [$a['unit_label'], $a['chart_account_id']] <=> [$b['unit_label'], $b['chart_account_id']]);

        $warnings = [];
        if ($before === []) {
            $warnings[] = ['code' => 'no_previous', 'message' => "Nada cobrado das unidades em {$previous}: sem comparação de variação."];
        }
        $active = count(array_filter($all, static fn (array $u): bool => $u['active']));
        $out = ['condominium_id' => $condominiumId, 'competence' => $competence, 'previous_competence' => $previous, 'tolerance_percent' => $tol,
            'active_units' => $active, 'billed_units' => count(array_filter(array_keys($current), static fn (int $id): bool => $all[$id]['active'] ?? false)),
            'total_cents' => array_sum($current), 'previous_total_cents' => array_sum($before),
            'without_unit_cents' => array_sum(array_column($withoutUnit, 'amount_cents')), 'summary' => [], 'warnings' => $warnings];
        foreach ($sections as $key => $items) {
            $out['summary'][$key] = count($items);
            $out[$key] = ['total' => count($items), 'truncated' => count($items) > self::LIMIT, 'items' => array_slice($items, 0, self::LIMIT)];
        }
        return $out;
    }
}
