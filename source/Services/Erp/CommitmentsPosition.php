<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Posição dos empenhos do condomínio (ADR 0238), somente leitura. O cadastro de empenhos (AP-128, ADR 0050) lista
 * empenho por empenho: a despesa reservada antes do pagamento, liquidada depois com a data e, se houver, o lançamento.
 * Nenhum relatório mostra quanto está empenhado e ainda não liquidado, há quanto tempo, quanto se liquidou no período e
 * em quanto tempo, nem quantas liquidações ficaram sem o lançamento que as pagou (controle que o conselho fiscal cobra).
 *
 * Reusa o que já existe: as situações do cadastro (RegistryService::definition('commitments'): Empenhado, Liquidado e
 * Cancelado) e a regra das listas (sem os excluídos).
 *
 * Regra pura summarize():
 * - rows: uma linha por situação (sempre as três) com os empenhos feitos no período (committed_on de from a to):
 *   commitments e amount_cents; totals é a soma das linhas;
 * - settlement: dos liquidados do período, with_entry e without_entry (com e sem o lançamento que pagou, somando count),
 *   dated (liquidação na data do empenho ou depois), days_total, avg_days (1 casa) e max_days;
 * - open: os empenhados ainda em aberto feitos até to (de qualquer período), pela idade em to (até 30 dias, 31 a 60, 61
 *   a 90, mais de 90), com commitments e amount_cents; total é a soma das faixas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * situações, as contagens e os valores: nenhuma descrição, fornecedor, documento ou observação.
 */
final class CommitmentsPosition
{
    public const MAX_DAYS = 366;
    public const MAX_ROWS = 50000;
    public const AGES = ['ate_30' => 30, 'de_31_a_60' => 60, 'de_61_a_90' => 90, 'mais_de_90' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Faixa de idade de um empenho em aberto. */
    public static function age(int $days): string
    {
        foreach (self::AGES as $name => $limit) {
            if ($limit !== null && $days <= $limit) {
                return $name;
            }
        }
        return 'mais_de_90';
    }

    private static function days(string $from, string $to): int
    {
        return (int)(new DateTimeImmutable(substr($from, 0, 10)))->diff(new DateTimeImmutable(substr($to, 0, 10)))->format('%r%a');
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{status:string, amount_cents:int, committed_on:string, settled_on:?string, has_entry:bool}> $period
     * @param list<array{committed_on:string, amount_cents:int}> $open
     */
    public static function summarize(array $period, array $open, string $to): array
    {
        $rows = [];
        foreach (RegistryService::definition('commitments')['status'] as $status => $label) {
            $rows[$status] = ['status' => $status, 'status_label' => $label, 'commitments' => 0, 'amount_cents' => 0];
        }
        $settlement = ['count' => 0, 'with_entry' => 0, 'without_entry' => 0, 'dated' => 0, 'days_total' => 0, 'avg_days' => null, 'max_days' => null];
        foreach ($period as $c) {
            $status = isset($rows[$c['status']]) ? $c['status'] : 'active';
            $rows[$status]['commitments']++;
            $rows[$status]['amount_cents'] += $c['amount_cents'];
            if ($status !== 'settled') {
                continue;
            }
            $settlement['count']++;
            $settlement[$c['has_entry'] ? 'with_entry' : 'without_entry']++;
            $settled = $c['settled_on'] !== null && $c['settled_on'] !== '' ? substr($c['settled_on'], 0, 10) : null;
            if ($settled !== null && $settled >= substr($c['committed_on'], 0, 10)) {
                $days = self::days($c['committed_on'], $settled);
                $settlement['dated']++;
                $settlement['days_total'] += $days;
                $settlement['max_days'] = max($settlement['max_days'] ?? 0, $days);
            }
        }
        $settlement['avg_days'] = $settlement['dated'] > 0 ? round($settlement['days_total'] / $settlement['dated'], 1) : null;
        $ages = [];
        foreach (array_keys(self::AGES) as $age) {
            $ages[$age] = ['commitments' => 0, 'amount_cents' => 0];
        }
        foreach ($open as $c) {
            $age = self::age(max(0, self::days($c['committed_on'], $to)));
            $ages[$age]['commitments']++;
            $ages[$age]['amount_cents'] += $c['amount_cents'];
        }
        return [
            'rows' => array_values($rows),
            'totals' => ['commitments' => array_sum(array_column($rows, 'commitments')), 'amount_cents' => array_sum(array_column($rows, 'amount_cents'))],
            'settlement' => $settlement,
            'open' => ['by_age' => $ages, 'total' => ['commitments' => array_sum(array_column($ages, 'commitments')),
                'amount_cents' => array_sum(array_column($ages, 'amount_cents'))]],
        ];
    }

    public function build(User $user, mixed $condominiumId, mixed $from = null, mixed $to = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $to = $to === null || $to === '' ? date('Y-m-d') : $to;
        $from = $from === null || $from === '' ? (is_string($to) ? substr($to, 0, 8) . '01' : $to) : $from;
        $start = is_string($from) ? DateTimeImmutable::createFromFormat('!Y-m-d', $from) : false;
        $finish = is_string($to) ? DateTimeImmutable::createFromFormat('!Y-m-d', $to) : false;
        if ($start === false || $finish === false || $start->format('Y-m-d') !== $from || $finish->format('Y-m-d') !== $to) {
            throw new InvalidArgumentException('Período: AAAA-MM-DD.');
        }
        if ($from > $to || (int)$start->diff($finish)->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período inválido: início até o fim, com até %d dias.', self::MAX_DAYS));
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT status, amount_cents, committed_on, settled_on, entry_id IS NOT NULL AS has_entry FROM erp_commitments
            WHERE condominium_id = ? AND deleted_at IS NULL AND committed_on BETWEEN ? AND ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $period = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt = $this->pdo->prepare("SELECT committed_on, amount_cents FROM erp_commitments WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
            AND committed_on <= ? ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $to]);
        $open = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($period) > self::MAX_ROWS || count($open) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d empenhos: escolha um período menor.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize(
            array_map(static fn (array $c): array => ['status' => (string)$c['status'], 'amount_cents' => (int)$c['amount_cents'],
                'committed_on' => substr((string)$c['committed_on'], 0, 10), 'settled_on' => $c['settled_on'] !== null ? substr((string)$c['settled_on'], 0, 10) : null,
                'has_entry' => (bool)$c['has_entry']], $period),
            array_map(static fn (array $c): array => ['committed_on' => substr((string)$c['committed_on'], 0, 10), 'amount_cents' => (int)$c['amount_cents']], $open),
            $to);
    }
}
