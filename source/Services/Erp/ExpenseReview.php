<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência de despesas para o conselho fiscal (ADR 0188), somente leitura. Nas despesas válidas (ativas e não
 * excluídas) de um condomínio, na faixa de competências (ManagementReports::competences):
 *
 * - possible_duplicates: grupos de despesas do mesmo fornecedor com o mesmo valor e vencimentos a até 7 dias um do
 *   outro, ou com o mesmo número de documento (sem pontuação e sem caixa). É indício, não prova: o conselho confere.
 * - paid_without_supplier: despesa paga sem fornecedor (a quem foi pago?).
 * - paid_without_document: despesa paga sem número de documento (nota fiscal, recibo).
 * - spikes: conta analítica de despesa cujo total no mês passa de 1,5 vez a média dos 12 meses anteriores (com ao
 *   menos 3 meses com valor nesses 12; a média é só dos meses com valor) e a diferença é de R$ 100,00 ou mais.
 *
 * Cada lista vem limitada a LIMIT itens, com o total e a marca de corte. Do fornecedor só vão id e nome; documento,
 * dados bancários e Pix não saem. Um condomínio só, obrigatório e do escopo (fora = CondominiumAccessDenied, 404).
 */
final class ExpenseReview
{
    public const LIMIT = 200;
    public const DUPLICATE_DAYS = 7;
    public const SPIKE_RATIO = 1.5;
    public const SPIKE_MIN_CENTS = 10000;
    public const SPIKE_MIN_HISTORY = 3;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Grupos de possíveis duplicidades (regra pura). Cada despesa: id, supplier_id, amount_cents, due_on, document_number.
     *
     * @param list<array<string, mixed>> $expenses
     * @return list<array{reason:string, ids:list<int>}>
     */
    public static function duplicates(array $expenses): array
    {
        $groups = [];
        $byValue = [];
        $byDocument = [];
        foreach ($expenses as $e) {
            if ($e['supplier_id'] === null) {
                continue;
            }
            $byValue[$e['supplier_id'] . ':' . $e['amount_cents']][] = $e;
            $doc = strtoupper((string)preg_replace('/[^0-9A-Za-z]/', '', (string)($e['document_number'] ?? '')));
            if ($doc !== '') {
                $byDocument[$e['supplier_id'] . ':' . $doc][] = (int)$e['id'];
            }
        }
        foreach ($byValue as $list) {
            usort($list, static fn (array $a, array $b): int => [$a['due_on'], $a['id']] <=> [$b['due_on'], $b['id']]);
            $run = [(int)$list[0]['id']];
            for ($i = 1, $n = count($list); $i <= $n; $i++) {
                $close = $i < $n && (strtotime((string)$list[$i]['due_on']) - strtotime((string)$list[$i - 1]['due_on'])) <= self::DUPLICATE_DAYS * 86400;
                if ($close) {
                    $run[] = (int)$list[$i]['id'];
                    continue;
                }
                if (count($run) > 1) {
                    $groups[] = ['reason' => 'same_value', 'ids' => $run];
                }
                if ($i < $n) {
                    $run = [(int)$list[$i]['id']];
                }
            }
        }
        foreach ($byDocument as $ids) {
            if (count($ids) > 1) {
                sort($ids);
                $groups[] = ['reason' => 'same_document', 'ids' => $ids];
            }
        }
        usort($groups, static fn (array $a, array $b): int => [$a['ids'][0], $a['reason']] <=> [$b['ids'][0], $b['reason']]);
        return $groups;
    }

    /**
     * Pico do mês (regra pura): null quando não é pico.
     *
     * @param list<int> $history totais dos 12 meses anteriores (0 = mês sem valor, fora da média)
     * @return array{average_cents:int, ratio:float}|null
     */
    public static function spike(int $monthCents, array $history): ?array
    {
        $withValue = array_values(array_filter($history, static fn (int $v): bool => $v > 0));
        if (count($withValue) < self::SPIKE_MIN_HISTORY) {
            return null;
        }
        $average = (int)round(array_sum($withValue) / count($withValue));   // média dos meses com valor: histórico curto não vira pico falso
        if ($average <= 0 || $monthCents < $average * self::SPIKE_RATIO || $monthCents - $average < self::SPIKE_MIN_CENTS) {
            return null;
        }
        return ['average_cents' => $average, 'ratio' => round($monthCents / $average, 2)];
    }

    public function build(User $user, ?int $condominiumId, mixed $from, mixed $to): array
    {
        if ($condominiumId === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        [$from, $to] = ManagementReports::competences(is_string($from) ? $from : '', is_string($to) ? $to : '');
        $this->scope->assertAccess($user, $condominiumId);   // 404 antes de consultar
        $stmt = $this->pdo->prepare("SELECT e.id, e.competence, e.due_on, e.paid_on, e.amount_cents, e.description, e.document_number, e.supplier_id,
                COALESCE(NULLIF(s.trade_name, ''), s.legal_name) AS supplier_name, a.code AS account_code, a.name AS account_name
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id LEFT JOIN erp_suppliers s ON s.id = e.supplier_id
            WHERE e.condominium_id = :c AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN :f AND :t
            ORDER BY e.due_on, e.id");
        $stmt->execute(['c' => $condominiumId, 'f' => $from, 't' => $to]);
        $expenses = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $expenses[(int)$r['id']] = ['id' => (int)$r['id'], 'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'],
                'paid_on' => $r['paid_on'] === null ? null : (string)$r['paid_on'], 'amount_cents' => (int)$r['amount_cents'], 'description' => (string)$r['description'],
                'document_number' => $r['document_number'] === null || trim((string)$r['document_number']) === '' ? null : (string)$r['document_number'],
                'supplier_id' => $r['supplier_id'] === null ? null : (int)$r['supplier_id'], 'supplier_name' => $r['supplier_name'] === null ? null : (string)$r['supplier_name'],
                'account' => $r['account_code'] . ' ' . $r['account_name']];
        }
        $duplicates = array_map(static fn (array $g): array => $g + ['total_cents' => array_sum(array_map(static fn (int $id): int => $expenses[$id]['amount_cents'], $g['ids'])),
            'entries' => array_map(static fn (int $id): array => $expenses[$id], $g['ids'])], self::duplicates(array_values($expenses)));
        $paid = array_values(array_filter($expenses, static fn (array $e): bool => $e['paid_on'] !== null));
        $sections = [
            'possible_duplicates' => $duplicates,
            'paid_without_supplier' => array_values(array_filter($paid, static fn (array $e): bool => $e['supplier_id'] === null)),
            'paid_without_document' => array_values(array_filter($paid, static fn (array $e): bool => $e['document_number'] === null)),
            'spikes' => $this->spikes($condominiumId, $from, $to),
        ];
        $out = ['condominium_id' => $condominiumId, 'from' => $from, 'to' => $to, 'expenses_count' => count($expenses),
            'expenses_cents' => array_sum(array_column($expenses, 'amount_cents')), 'summary' => []];
        foreach ($sections as $key => $items) {
            $out['summary'][$key] = count($items);
            $out[$key] = ['total' => count($items), 'truncated' => count($items) > self::LIMIT, 'items' => array_slice($items, 0, self::LIMIT)];
        }
        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function spikes(int $condominiumId, string $from, string $to): array
    {
        $start = (new \DateTimeImmutable($from . '-01'))->modify('-12 months')->format('Y-m');
        $stmt = $this->pdo->prepare("SELECT a.id, a.code, a.name, e.competence, SUM(e.amount_cents) AS cents
            FROM erp_entries e JOIN erp_chart_accounts a ON a.id = e.chart_account_id
            WHERE e.condominium_id = :c AND e.nature = 'despesa' AND e.status = 'active' AND e.deleted_at IS NULL AND e.competence BETWEEN :s AND :t
            GROUP BY a.id, a.code, a.name, e.competence");
        $stmt->execute(['c' => $condominiumId, 's' => $start, 't' => $to]);
        $accounts = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $accounts[(int)$r['id']] ??= ['code' => (string)$r['code'], 'name' => (string)$r['name'], 'months' => []];
            $accounts[(int)$r['id']]['months'][(string)$r['competence']] = (int)$r['cents'];
        }
        $out = [];
        foreach ($accounts as $id => $a) {
            foreach ($a['months'] as $competence => $cents) {
                if ($competence < $from) {
                    continue;
                }
                $month = new \DateTimeImmutable($competence . '-01');
                $history = [];
                for ($k = 12; $k >= 1; $k--) {
                    $history[] = $a['months'][$month->modify("-{$k} months")->format('Y-m')] ?? 0;
                }
                $spike = self::spike($cents, $history);
                if ($spike !== null) {
                    $out[] = ['chart_account_id' => $id, 'code' => $a['code'], 'name' => $a['name'], 'competence' => $competence, 'month_cents' => $cents] + $spike;
                }
            }
        }
        usort($out, static fn (array $x, array $y): int => [$x['competence'], $x['code']] <=> [$y['competence'], $y['code']]);
        return $out;
    }
}
