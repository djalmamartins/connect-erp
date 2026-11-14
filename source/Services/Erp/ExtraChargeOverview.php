<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Situação das cobranças extras e multas avulsas do condomínio (ADR 0288), somente leitura. As cobranças extras e as
 * multas (atalhos de lançamento, ADRs 0057 e 0080) geram uma conta a receber por unidade, ligada pela extra_charge_id.
 * A lista mostra uma cobrança por vez, e ninguém vê, de uma vez, quanto foi lançado, quanto já entrou, quanto está em
 * aberto ou vencido e quanto virou acordo, por tipo.
 *
 * Reusa o que já existe: os tipos e rótulos de EntryShortcuts::CHARGE_KINDS, a ligação erp_entries.extra_charge_id, a
 * baixa (paid_on) e o acordo (agreement_id, com a conta original cancelada, ADR 0039) como as contas a receber já usam. O
 * dia é o do banco.
 *
 * Regra pura state() de uma conta da cobrança: paid (ativa e baixada), overdue (ativa, em aberto e vencida antes de
 * hoje), open (ativa, em aberto e vencendo hoje ou depois), agreement (cancelada e ligada a um acordo) ou null
 * (cancelada sem acordo: fica de fora dos valores).
 *
 * Regra pura summarize():
 * - rows: uma linha por tipo de CHARGE_KINDS, sempre todos, com charges (ativas), cancelled (canceladas), e, só das
 *   contas das cobranças ativas, a quantidade e o valor por situação de STATES, que somam entries e amount_cents, e o
 *   vencimento mais antigo em atraso; totals soma as linhas;
 * - overdue_ids: cobranças com conta vencida, da que venceu antes, até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * tipos, contagens, valores, datas e ids: nenhuma descrição, motivo, unidade nem quem lançou.
 */
final class ExtraChargeOverview
{
    public const MAX_ROWS = 50000;
    public const MAX_IDS = 200;
    /** Situação de uma conta da cobrança. */
    public const STATES = ['paid' => 'Recebida', 'open' => 'Em aberto', 'overdue' => 'Vencida', 'agreement' => 'Em acordo'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação (regra pura) de uma conta da cobrança no dia; null = cancelada sem acordo. */
    public static function state(string $status, ?string $paidOn, string $dueOn, bool $inAgreement, string $today): ?string
    {
        return match (true) {
            $status !== 'active' => $inAgreement ? 'agreement' : null,
            $paidOn !== null => 'paid',
            $dueOn < $today => 'overdue',
            default => 'open',
        };
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string}> $charges
     * @param list<array{charge_id:int, amount_cents:int, status:string, paid_on:?string, due_on:string, in_agreement:bool}> $entries
     */
    public static function summarize(array $charges, array $entries, string $today): array
    {
        $zero = ['charges' => 0, 'cancelled' => 0, 'entries' => 0, 'amount_cents' => 0];
        foreach (array_keys(self::STATES) as $state) {
            $zero[$state] = 0;
            $zero[$state . '_cents'] = 0;
        }
        $counts = array_fill_keys(array_keys(EntryShortcuts::CHARGE_KINDS), $zero + ['oldest_overdue_on' => null]);
        $active = [];
        foreach ($charges as $c) {
            if (!isset($counts[$c['kind']])) {
                continue;
            }
            if ($c['status'] === 'active') {
                $counts[$c['kind']]['charges']++;
                $active[$c['id']] = $c['kind'];
            } else {
                $counts[$c['kind']]['cancelled']++;
            }
        }
        $overdue = [];
        foreach ($entries as $e) {
            $kind = $active[$e['charge_id']] ?? null;
            $state = $kind === null ? null : self::state($e['status'], $e['paid_on'], $e['due_on'], $e['in_agreement'], $today);
            if ($state === null) {
                continue;
            }
            $counts[$kind]['entries']++;
            $counts[$kind]['amount_cents'] += $e['amount_cents'];
            $counts[$kind][$state]++;
            $counts[$kind][$state . '_cents'] += $e['amount_cents'];
            if ($state === 'overdue') {
                $oldest = $counts[$kind]['oldest_overdue_on'];
                $counts[$kind]['oldest_overdue_on'] = $oldest === null || $e['due_on'] < $oldest ? $e['due_on'] : $oldest;
                $overdue[$e['charge_id']] = min($overdue[$e['charge_id']] ?? $e['due_on'], $e['due_on']);
            }
        }
        $rows = [];
        $totals = $zero + ['oldest_overdue_on' => null];
        foreach (EntryShortcuts::CHARGE_KINDS as $kind => $label) {
            $rows[] = ['kind' => $kind, 'kind_label' => $label] + $counts[$kind];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$kind][$key];
            }
            $oldest = $counts[$kind]['oldest_overdue_on'];
            if ($oldest !== null && ($totals['oldest_overdue_on'] === null || $oldest < $totals['oldest_overdue_on'])) {
                $totals['oldest_overdue_on'] = $oldest;
            }
        }
        $order = [];
        foreach ($overdue as $id => $dueOn) {
            $order[] = [$dueOn, $id];
        }
        sort($order);
        $ids = array_column($order, 1);
        return ['on' => $today, 'rows' => $rows, 'totals' => $totals, 'overdue_ids' => array_slice($ids, 0, self::MAX_IDS),
            'overdue_truncated' => count($ids) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, ?string $today = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, kind, status FROM erp_extra_charges WHERE condominium_id = ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $charges = $stmt->fetchAll(PDO::FETCH_ASSOC);
        // As contas ligadas às cobranças deste condomínio (a conta e a cobrança no mesmo condomínio).
        $stmt = $this->pdo->prepare('SELECT e.extra_charge_id, e.amount_cents, e.status, e.paid_on, e.due_on, e.agreement_id FROM erp_entries e
            JOIN erp_extra_charges x ON x.id = e.extra_charge_id AND x.condominium_id = e.condominium_id
            WHERE e.condominium_id = ? AND e.deleted_at IS NULL ORDER BY e.id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id]);
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($charges) > self::MAX_ROWS || count($entries) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d cobranças ou contas: fale com o suporte.', self::MAX_ROWS));
        }
        // O dia do banco, para comparar com os vencimentos gravados.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        return ['condominium_id' => (int)$id] + self::summarize(
            array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status']], $charges),
            array_map(static fn (array $r): array => ['charge_id' => (int)$r['extra_charge_id'], 'amount_cents' => (int)$r['amount_cents'],
                'status' => (string)$r['status'], 'paid_on' => $r['paid_on'] === null ? null : substr((string)$r['paid_on'], 0, 10),
                'due_on' => substr((string)$r['due_on'], 0, 10), 'in_agreement' => $r['agreement_id'] !== null], $entries),
            $today
        );
    }
}
