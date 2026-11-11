<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Gastos por fornecedor (ADR 0190), somente leitura, para o síndico e o conselho fiscal: com quem o condomínio gasta,
 * quanto e com que concentração. Despesas válidas (ativas e não excluídas) de um condomínio, por competência, na faixa
 * do ManagementReports::competences (até 24 meses):
 *
 * - por fornecedor: lançamentos, total, pago e em aberto, participação no total com fornecedor, as 3 contas de maior
 *   valor, a primeira e a última competência, os meses com gasto, e o total no período anterior de mesmo tamanho com a
 *   variação;
 * - despesas sem fornecedor ficam à parte (without_supplier), fora do ranking e da concentração;
 * - concentração: participação do maior e dos 5 maiores e o índice HHI (soma dos quadrados das participações em %,
 *   de 0 a 10.000);
 * - histórico curto: se o período anterior não tem nenhuma despesa (condomínio novo, ou o laboratório, que começa em
 *   jan/26), a variação vem null em todos e o aviso no_previous_period, em vez de "+100%".
 *
 * O documento do fornecedor sai mascarado (CollectionReports::maskDocument); banco e Pix não saem. Até LIMIT
 * fornecedores, com a marca de corte. Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class SupplierSpend
{
    public const LIMIT = 200;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return array{0:string, 1:string} o período anterior de mesmo tamanho (regra pura) */
    public static function previousPeriod(string $from, string $to): array
    {
        $start = new \DateTimeImmutable($from . '-01');
        $end = new \DateTimeImmutable($to . '-01');
        $months = ((int)$end->format('Y') - (int)$start->format('Y')) * 12 + (int)$end->format('n') - (int)$start->format('n') + 1;
        return [$start->modify("-{$months} months")->format('Y-m'), $start->modify('-1 month')->format('Y-m')];
    }

    /**
     * Concentração (regra pura) a partir dos totais por fornecedor.
     *
     * @param list<int> $totals
     * @return array{top1_percent:?float, top5_percent:?float, hhi:?int}
     */
    public static function concentration(array $totals): array
    {
        $sum = array_sum($totals);
        if ($sum <= 0) {
            return ['top1_percent' => null, 'top5_percent' => null, 'hhi' => null];
        }
        rsort($totals);
        $hhi = 0.0;
        foreach ($totals as $t) {
            $hhi += ($t * 100 / $sum) ** 2;
        }
        return ['top1_percent' => round($totals[0] * 100 / $sum, 2), 'top5_percent' => round(array_sum(array_slice($totals, 0, 5)) * 100 / $sum, 2), 'hhi' => (int)round($hhi)];
    }

    public function build(User $user, ?int $condominiumId, mixed $from, mixed $to): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$from, $to] = ManagementReports::competences(is_string($from) ? $from : '', is_string($to) ? $to : '');
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        [$prevFrom, $prevTo] = self::previousPeriod($from, $to);

        $stmt = $this->pdo->prepare("SELECT e.supplier_id, e.competence, e.paid_on, e.amount_cents, a.code, a.name AS account_name
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id = :c AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN :f AND :t");
        $stmt->execute(['c' => $condominiumId, 'f' => $from, 't' => $to]);
        $groups = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $key = $r['supplier_id'] === null ? 0 : (int)$r['supplier_id'];
            $g = &$groups[$key];
            $g ??= ['entries' => 0, 'total_cents' => 0, 'paid_cents' => 0, 'accounts' => [], 'competences' => []];
            $cents = (int)$r['amount_cents'];
            $g['entries']++;
            $g['total_cents'] += $cents;
            $g['paid_cents'] += $r['paid_on'] === null ? 0 : $cents;
            $code = (string)$r['code'];
            $g['accounts'][$code] ??= ['code' => $code, 'name' => (string)$r['account_name'], 'amount_cents' => 0];
            $g['accounts'][$code]['amount_cents'] += $cents;
            $g['competences'][(string)$r['competence']] = true;
            unset($g);
        }
        $prev = $this->pdo->prepare("SELECT COALESCE(supplier_id, 0) AS supplier, SUM(amount_cents) FROM erp_entries
            WHERE condominium_id = :c AND nature = 'despesa' AND status = 'active' AND deleted_at IS NULL AND competence BETWEEN :f AND :t GROUP BY COALESCE(supplier_id, 0)");
        $prev->execute(['c' => $condominiumId, 'f' => $prevFrom, 't' => $prevTo]);
        $previous = array_map('intval', $prev->fetchAll(PDO::FETCH_KEY_PAIR));
        $hasPrevious = $previous !== [];

        $names = [];
        $ids = array_values(array_filter(array_keys($groups)));
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $s = $this->pdo->prepare("SELECT id, COALESCE(NULLIF(trade_name, ''), legal_name) AS name, document FROM erp_suppliers WHERE id IN ({$marks})");
            $s->execute($ids);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names[(int)$row['id']] = ['name' => (string)$row['name'], 'document' => CollectionReports::maskDocument($row['document'])];
            }
        }
        $identifiedTotal = array_sum(array_map(static fn (array $g): int => $g['total_cents'], array_filter($groups, static fn (int $k): bool => $k !== 0, ARRAY_FILTER_USE_KEY)));
        $row = static function (int $key, array $g) use ($names, $previous, $hasPrevious, $identifiedTotal): array {
            usort($g['accounts'], static fn (array $a, array $b): int => [$b['amount_cents'], $a['code']] <=> [$a['amount_cents'], $b['code']]);
            $competences = array_keys($g['competences']);
            sort($competences);
            $before = $previous[$key] ?? 0;
            return ['supplier_id' => $key === 0 ? null : $key, 'name' => $key === 0 ? null : ($names[$key]['name'] ?? null),
                'document_masked' => $key === 0 ? null : ($names[$key]['document'] ?? null), 'entries' => $g['entries'], 'total_cents' => $g['total_cents'],
                'paid_cents' => $g['paid_cents'], 'open_cents' => $g['total_cents'] - $g['paid_cents'],
                'share_percent' => $key === 0 || $identifiedTotal <= 0 ? null : round($g['total_cents'] * 100 / $identifiedTotal, 2),
                'top_accounts' => array_slice($g['accounts'], 0, 3), 'first_competence' => $competences[0], 'last_competence' => end($competences),
                'months_active' => count($competences), 'previous_cents' => $hasPrevious ? $before : null,
                'variation_percent' => $hasPrevious && $before > 0 ? round(($g['total_cents'] - $before) * 100 / $before, 2) : null];
        };
        $ranking = [];
        foreach ($groups as $key => $g) {
            if ($key !== 0) {
                $ranking[] = $row($key, $g);
            }
        }
        usort($ranking, static fn (array $a, array $b): int => [$b['total_cents'], $a['supplier_id']] <=> [$a['total_cents'], $b['supplier_id']]);
        $warnings = [];
        if (!$hasPrevious) {
            $warnings[] = ['code' => 'no_previous_period', 'message' => "Nenhuma despesa de {$prevFrom} a {$prevTo}: sem comparação com o período anterior."];
        }
        $gone = $hasPrevious ? array_values(array_filter(array_keys($previous), static fn (int $k): bool => $k !== 0 && !isset($groups[$k]))) : [];
        return ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to, 'previous_from' => $prevFrom, 'previous_to' => $prevTo,
            'expenses_cents' => array_sum(array_column($groups, 'total_cents')), 'identified_cents' => $identifiedTotal,
            'suppliers' => count($ranking), 'truncated' => count($ranking) > self::LIMIT, 'rows' => array_slice($ranking, 0, self::LIMIT),
            'without_supplier' => isset($groups[0]) ? $row(0, $groups[0]) : null,
            'concentration' => self::concentration(array_column($ranking, 'total_cents')),
            'previous_only_suppliers' => count($gone), 'warnings' => $warnings];
    }

    /** Tabela do CSV (regra pura), com cada célula saneada (CollectionReports::csvCell). */
    public static function csvTable(array $report): array
    {
        $money = static fn (?int $c): string => $c === null ? '' : number_format($c / 100, 2, ',', '');
        $pct = static fn (?float $p): string => $p === null ? '' : number_format($p, 2, ',', '');
        $rows = [];
        foreach (array_merge($report['rows'], $report['without_supplier'] === null ? [] : [$report['without_supplier']]) as $r) {
            $rows[] = array_map([CollectionReports::class, 'csvCell'], [$r['name'] ?? 'Sem fornecedor', (string)($r['document_masked'] ?? ''), (string)$r['entries'],
                $money($r['total_cents']), $money($r['paid_cents']), $money($r['open_cents']), $pct($r['share_percent']),
                implode(', ', array_map(static fn (array $a): string => $a['code'] . ' ' . $a['name'], $r['top_accounts'])),
                $r['first_competence'], $r['last_competence'], (string)$r['months_active'], $money($r['previous_cents']), $pct($r['variation_percent'])]);
        }
        return ['header' => ['Fornecedor', 'CPF/CNPJ', 'Lançamentos', 'Total', 'Pago', 'Em aberto', 'Participação (%)', 'Principais contas',
            'Primeira competência', 'Última competência', 'Meses com gasto', 'Período anterior', 'Variação (%)'], 'rows' => $rows];
    }
}
