<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;

/**
 * Extrato da unidade (ADR 0184), somente leitura: as contas a receber da unidade num período (até 366 dias), com o que
 * foi cobrado, pago e o que ficou em aberto. Base do atendimento ao condômino e da conferência antes de uma declaração
 * de quitação (AP-125).
 *
 * - Linhas: as receitas da unidade com vencimento no período, com a situação de cada uma: paid (paga), open (a vencer),
 *   overdue (vencida e em aberto) ou renegotiated (entrou num acordo; as parcelas do acordo aparecem como linhas).
 *   Lançamento cancelado fora de acordo ou excluído não entra.
 * - Saldo anterior: o que vencia antes do período e não estava pago no primeiro dia dele.
 * - Totais: cobrado no período (sem as renegociadas, para não contar duas vezes com as parcelas), pago no período (pela
 *   data do pagamento, de qualquer vencimento), em aberto no fim e vencido na data (o fim do período ou hoje).
 *
 * Sem nome de morador: a unidade é o rótulo. Escopo (ADR-002): pela unidade; fora do escopo ou inexistente =
 * CondominiumAccessDenied (404), sem dizer se existe. LIMIT nas linhas (MAX_LINES), com truncated.
 */
final class UnitStatement
{
    public const MAX_LINES = 2000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Situação de uma conta a receber (regra pura).
     *
     * @param array{status:string, paid_on:?string, due_on:string, agreement_role:?string} $row
     */
    public static function situation(array $row, string $asOf): string
    {
        if (($row['agreement_role'] ?? null) === 'original') {
            return 'renegotiated';
        }
        if ($row['paid_on'] !== null && $row['paid_on'] !== '' && $row['paid_on'] <= $asOf) {
            return 'paid';
        }
        return $row['due_on'] < $asOf ? 'overdue' : 'open';
    }

    public function build(User $user, mixed $unitId, mixed $from, mixed $to, ?string $today = null): array
    {
        [$from, $to] = AccountingReports::period($from, $to);   // 422 com período inválido
        $id = filter_var($unitId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false || $id === null) {
            throw new InvalidArgumentException('Escolha a unidade.');
        }
        $stmt = $this->pdo->prepare('SELECT u.id, u.condominium_id, u.label FROM erp_units u WHERE u.id = ? AND u.deleted_at IS NULL');
        $stmt->execute([$id]);
        $unit = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($unit) || !$this->scope->canAccess($user, (int)$unit['condominium_id'])) {
            throw new CondominiumAccessDenied(is_array($unit) ? (int)$unit['condominium_id'] : 0);
        }
        $asOf = min($to, $today ?? date('Y-m-d'));
        $valid = "e.unit_id = :u AND e.nature = 'receita' AND e.deleted_at IS NULL
            AND (e.status = 'active' OR (e.status = 'inactive' AND e.agreement_role = 'original'))";

        $lines = $this->pdo->prepare("SELECT e.id, e.description, e.competence, e.due_on, e.paid_on, e.amount_cents, e.status, e.agreement_id, e.agreement_role
            FROM erp_entries e WHERE {$valid} AND e.due_on BETWEEN :f AND :t ORDER BY e.due_on, e.id LIMIT " . (self::MAX_LINES + 1));
        $lines->execute(['u' => $id, 'f' => $from, 't' => $to]);
        $rows = $lines->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($rows) > self::MAX_LINES;
        $rows = array_map(static fn (array $r): array => ['entry_id' => (int)$r['id'], 'description' => (string)$r['description'],
            'competence' => (string)$r['competence'], 'due_on' => (string)$r['due_on'], 'paid_on' => $r['paid_on'] !== null ? (string)$r['paid_on'] : null,
            'amount_cents' => (int)$r['amount_cents'], 'agreement_id' => $r['agreement_id'] !== null ? (int)$r['agreement_id'] : null,
            'situation' => self::situation(['status' => (string)$r['status'], 'paid_on' => $r['paid_on'], 'due_on' => (string)$r['due_on'],
                'agreement_role' => $r['agreement_role']], $asOf)], array_slice($rows, 0, self::MAX_LINES));

        // Saldo anterior: vencido antes do período, não renegociado e sem pagamento até a véspera do período.
        // Cada parâmetro aparece uma vez por consulta (PDO com prepare nativo não aceita o mesmo nome repetido).
        $opening = $this->sum("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e WHERE {$valid} AND e.status = 'active' AND e.due_on < :f1
            AND (e.paid_on IS NULL OR e.paid_on >= :f2)", ['u' => $id, 'f1' => $from, 'f2' => $from]);
        $paid = $this->sum("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e WHERE {$valid} AND e.status = 'active' AND e.paid_on BETWEEN :f AND :t",
            ['u' => $id, 'f' => $from, 't' => $to]);
        $openEnd = $this->sum("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e WHERE {$valid} AND e.status = 'active' AND e.due_on <= :t1
            AND (e.paid_on IS NULL OR e.paid_on > :t2)", ['u' => $id, 't1' => $to, 't2' => $to]);
        $overdue = $this->sum("SELECT COALESCE(SUM(e.amount_cents), 0) FROM erp_entries e WHERE {$valid} AND e.status = 'active' AND e.due_on < :a1
            AND (e.paid_on IS NULL OR e.paid_on > :a2)", ['u' => $id, 'a1' => $asOf, 'a2' => $asOf]);
        $charged = array_sum(array_map(static fn (array $r): int => $r['situation'] === 'renegotiated' ? 0 : $r['amount_cents'], $rows));
        $count = static fn (string $s): int => count(array_filter($rows, static fn (array $r): bool => $r['situation'] === $s));
        return [
            'unit' => ['id' => (int)$unit['id'], 'condominium_id' => (int)$unit['condominium_id'], 'label' => (string)$unit['label']],
            'from' => $from, 'to' => $to, 'as_of' => $asOf, 'truncated' => $truncated,
            'rows' => $rows,
            'totals' => ['opening_cents' => $opening, 'charged_cents' => $charged, 'paid_cents' => $paid, 'open_cents' => $openEnd, 'overdue_cents' => $overdue,
                'paid' => $count('paid'), 'open' => $count('open'), 'overdue' => $count('overdue'), 'renegotiated' => $count('renegotiated')],
        ];
    }

    /**
     * O extrato em uma tabela para CSV (regra pura). Texto passa por CollectionReports::csvCell; valores em reais com
     * vírgula; datas em dd/mm/aaaa.
     *
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function csvTable(array $statement): array
    {
        $money = static fn (mixed $c): string => number_format(((int)$c) / 100, 2, ',', '');
        $cell = static fn (mixed $v): string => CollectionReports::csvCell((string)$v);
        $date = static fn (?string $d): string => $d === null || $d === '' ? '' : date('d/m/Y', (int)strtotime($d));
        $labels = ['paid' => 'Paga', 'open' => 'A vencer', 'overdue' => 'Vencida', 'renegotiated' => 'Renegociada (acordo)'];
        $t = $statement['totals'] ?? [];
        $rows = [['', '', $cell('Saldo anterior em ' . $date($statement['from'] ?? null)), '', '', '', $money($t['opening_cents'] ?? 0)]];
        foreach ($statement['rows'] ?? [] as $r) {
            $rows[] = [$date($r['due_on']), $cell($r['competence']), $cell($r['description']), $date($r['paid_on']), $labels[$r['situation']] ?? $r['situation'],
                $r['agreement_id'] !== null ? (string)$r['agreement_id'] : '', $money($r['amount_cents'])];
        }
        foreach (['Cobrado no período' => 'charged_cents', 'Pago no período' => 'paid_cents', 'Em aberto no fim do período' => 'open_cents',
            'Vencido na data' => 'overdue_cents'] as $label => $key) {
            $rows[] = ['', '', $label, '', '', '', $money($t[$key] ?? 0)];
        }
        if (!empty($statement['truncated'])) {
            $rows[] = ['', '', 'Extrato parcial: passou do limite de linhas.', '', '', '', ''];
        }
        return ['header' => ['Vencimento', 'Competência', 'Descrição', 'Pago em', 'Situação', 'Acordo', 'Valor'], 'rows' => $rows];
    }

    private function sum(string $sql, array $params): int
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int)$stmt->fetchColumn();
    }
}
