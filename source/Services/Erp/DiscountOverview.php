<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Descontos concedidos em contas a receber do condomínio, mês a mês (ADR 0290), somente leitura. O desconto (AP-026,
 * EntryShortcuts::discount, ADR 0057) baixa o valor de uma conta em aberto e deixa a trilha em erp_entry_adjustments
 * (antes, desconto, depois). A trilha só aparece conta por conta, e ninguém vê, de uma vez, quanto foi dado de desconto
 * por mês, se os descontos são pequenos ou levam a conta quase a zero, e quais contas foram zeradas.
 *
 * Reusa o que já existe: a trilha de descontos (erp_entry_adjustments, kind discount, com before_cents e after_cents,
 * gravada pelo EntryShortcuts::discount) e a permissão de ver a trilha (erp.entries.view, a do GET
 * /entries/{id}/adjustments). Os meses são os do relógio do banco, porque created_at foi gravado por ele.
 *
 * Regra pura:
 * - months(): os últimos N meses (AAAA-MM), do mais antigo ao atual, com N de 1 a MAX_MONTHS (padrão DEFAULT_MONTHS);
 * - band(): a faixa do desconto em relação ao valor antes dele, pelos limites de BANDS (em %; o último sem limite);
 * - summarize(): uma linha por mês, sempre todos, com discounts (descontos), entries (contas distintas no mês),
 *   discount_cents e before_cents; totals soma as linhas; by_band conta os descontos por faixa (soma
 *   totals.discounts); zeroed conta os descontos que levaram a conta a zero; zeroed_entry_ids e largest_entry_ids
 *   (pelo maior desconto) até MAX_IDS, com truncated.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só
 * meses, contagens, valores e ids de contas: nenhum motivo, descrição, unidade nem quem deu o desconto.
 */
final class DiscountOverview
{
    public const MAX_ROWS = 50000;
    public const MAX_IDS = 200;
    public const DEFAULT_MONTHS = 12;
    public const MAX_MONTHS = 36;
    /** Faixa do desconto, em % do valor antes dele (o limite entra na faixa); null = sem limite. */
    public const BANDS = ['ate_10' => 10, 'de_10_a_50' => 50, 'mais_de_50' => null];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** @return list<string> os últimos $count meses até o de $today, do mais antigo ao atual (regra pura). */
    public static function months(string $today, int $count): array
    {
        $current = new \DateTimeImmutable(substr($today, 0, 7) . '-01');
        $out = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $out[] = $current->modify("-{$i} months")->format('Y-m');
        }
        return $out;
    }

    /** Faixa (regra pura) do desconto em relação ao valor antes dele. */
    public static function band(int $discountCents, int $beforeCents): string
    {
        foreach (self::BANDS as $band => $limit) {
            if ($limit !== null && $discountCents * 100 <= $beforeCents * $limit) {
                return $band;
            }
        }
        return (string)array_key_last(self::BANDS);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{entry_id:int, month:string, discount_cents:int, before_cents:int, after_cents:int}> $adjustments
     * @param list<string> $months
     */
    public static function summarize(array $adjustments, array $months): array
    {
        $zero = ['discounts' => 0, 'entries' => 0, 'discount_cents' => 0, 'before_cents' => 0];
        $counts = array_fill_keys($months, $zero);
        $distinct = array_fill_keys($months, []);
        $byBand = array_fill_keys(array_keys(self::BANDS), 0);
        $zeroedCount = 0;
        $zeroed = [];
        $largest = [];
        foreach ($adjustments as $a) {
            if (!isset($counts[$a['month']])) {
                continue;   // fora da janela
            }
            $counts[$a['month']]['discounts']++;
            $counts[$a['month']]['discount_cents'] += $a['discount_cents'];
            $counts[$a['month']]['before_cents'] += $a['before_cents'];
            $distinct[$a['month']][$a['entry_id']] = true;
            $byBand[self::band($a['discount_cents'], $a['before_cents'])]++;
            if ($a['after_cents'] === 0) {
                $zeroedCount++;
                $zeroed[$a['entry_id']] = true;
            }
            $largest[$a['entry_id']] = max($largest[$a['entry_id']] ?? 0, $a['discount_cents']);
        }
        $rows = [];
        $totals = $zero;
        foreach ($months as $month) {
            $counts[$month]['entries'] = count($distinct[$month]);
            $rows[] = ['month' => $month] + $counts[$month];
            foreach ($zero as $key => $_) {
                $totals[$key] += $counts[$month][$key];
            }
        }
        $zeroedIds = array_keys($zeroed);
        sort($zeroedIds);
        $order = [];
        foreach ($largest as $id => $cents) {
            $order[] = [-$cents, $id];
        }
        sort($order);
        $largestIds = array_column($order, 1);
        return ['months' => count($months), 'from' => $months[0] ?? null, 'to' => $months[count($months) - 1] ?? null, 'rows' => $rows, 'totals' => $totals,
            'by_band' => $byBand, 'zeroed' => $zeroedCount,
            'zeroed_entry_ids' => array_slice($zeroedIds, 0, self::MAX_IDS), 'zeroed_truncated' => count($zeroedIds) > self::MAX_IDS,
            'largest_entry_ids' => array_slice($largestIds, 0, self::MAX_IDS), 'largest_truncated' => count($largestIds) > self::MAX_IDS];
    }

    public function build(User $user, mixed $condominiumId, mixed $months = null, ?string $today = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $id = $int($condominiumId);
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $count = self::DEFAULT_MONTHS;
        if ($months !== null && $months !== '') {
            $count = $int($months);
            if ($count === false || $count > self::MAX_MONTHS) {
                throw new InvalidArgumentException(sprintf('Meses: de 1 a %d.', self::MAX_MONTHS));
            }
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        // O dia do banco: created_at foi gravado por ele.
        $today ??= substr((string)$this->pdo->query('SELECT NOW()')->fetchColumn(), 0, 10);
        $window = self::months($today, (int)$count);
        $stmt = $this->pdo->prepare("SELECT a.entry_id, a.created_at, a.amount_cents, a.before_cents, a.after_cents FROM erp_entry_adjustments a
            JOIN erp_entries e ON e.id = a.entry_id AND e.condominium_id = a.condominium_id
            WHERE a.condominium_id = ? AND a.kind = 'discount' AND e.deleted_at IS NULL AND a.created_at >= ? ORDER BY a.id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $window[0] . '-01 00:00:00']);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($raw) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d descontos no período: escolha menos meses.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id] + self::summarize(array_map(static fn (array $r): array => ['entry_id' => (int)$r['entry_id'],
            'month' => substr((string)$r['created_at'], 0, 7), 'discount_cents' => (int)$r['amount_cents'], 'before_cents' => (int)$r['before_cents'],
            'after_cents' => (int)$r['after_cents']], $raw), $window);
    }
}
