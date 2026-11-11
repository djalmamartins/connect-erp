<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;

/**
 * Conferência das leituras de consumo de um mês (ADR 0220), somente leitura. As leituras de água, gás e energia
 * (MeterReadings, AP-041/AP-042) viram cobrança da unidade; os relatórios existentes (ManagementReports e ChartReports)
 * mostram só totais. Nada aponta, antes de cobrar, o relógio sem leitura no mês, o consumo muito acima ou abaixo do
 * costume da unidade (vazamento, leitura digitada errada), o consumo zero (relógio parado) ou a leitura que não continua
 * da anterior.
 *
 * Regra pura classify(), por relógio ativo do condomínio:
 * - missing: sem leitura na competência;
 * - sequence_break: a leitura anterior gravada não é a última leitura do relógio (ou a inicial, na primeira);
 * - zero: consumo zero;
 * - spike / drop: consumo acima de factor × a média ou abaixo da média ÷ factor. A média usa só os meses com leitura dos
 *   12 anteriores e exige MIN_HISTORY meses; com histórico curto, average fica null e nada é apontado por média
 *   (short_history conta quantos ficaram assim).
 * Totais: relógios, lidos, faltando, cobrados (entry_id) e quantos por problema.
 *
 * Não grava e não envia nada: a correção continua pelo lançamento da leitura (MeterReadings::record).
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem relógio (tipo e número), unidade
 * (id e rótulo), consumo e média: nenhum nome de morador.
 */
final class MeterReadingReview
{
    public const MIN_HISTORY = 3;
    public const HISTORY_MONTHS = 12;
    public const ISSUES = ['missing', 'sequence_break', 'zero', 'spike', 'drop'];
    public const MAX_METERS = 3000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Problemas de um relógio no mês (regra pura).
     *
     * @param array{previous_reading:float, consumption:float}|null $current leitura do mês
     * @param list<float> $history consumo dos meses anteriores que têm leitura
     * @return array{issues:list<string>, average:?float, ratio:?float}
     */
    public static function classify(?array $current, array $history, float $expectedPrevious, float $factor = 2.0): array
    {
        $average = count($history) >= self::MIN_HISTORY ? round(array_sum($history) / count($history), 3) : null;
        if ($current === null) {
            return ['issues' => ['missing'], 'average' => $average, 'ratio' => null];
        }
        $issues = [];
        if (abs($current['previous_reading'] - $expectedPrevious) > 0.0005) {
            $issues[] = 'sequence_break';
        }
        $consumption = $current['consumption'];
        $ratio = $average !== null && $average > 0 ? round($consumption / $average, 2) : null;
        if ($consumption <= 0.0) {
            $issues[] = 'zero';
        } elseif ($ratio !== null && $ratio > $factor) {
            $issues[] = 'spike';
        } elseif ($ratio !== null && $ratio < 1 / $factor) {
            $issues[] = 'drop';
        }
        return ['issues' => $issues, 'average' => $average, 'ratio' => $ratio];
    }

    public function build(User $user, mixed $condominiumId, mixed $competence, mixed $factor = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        if (!is_string($competence) || !RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência: AAAA-MM.');
        }
        $factor = $factor === null || $factor === '' ? 2.0 : (is_numeric($factor) ? (float)$factor : 0.0);
        if ($factor < 1.5 || $factor > 5.0) {
            throw new InvalidArgumentException('Fator: de 1,5 a 5.');
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar
        $from = (new DateTimeImmutable($competence . '-01'))->modify('-' . self::HISTORY_MONTHS . ' months')->format('Y-m');

        $stmt = $this->pdo->prepare("SELECT m.id, m.kind, m.number, m.unit_id, u.label AS unit_label, COALESCE(m.initial_reading, 0) AS initial_reading
            FROM erp_meters m LEFT JOIN erp_units u ON u.id = m.unit_id
            WHERE m.condominium_id = ? AND m.deleted_at IS NULL AND m.status = 'active' ORDER BY m.kind, m.number, m.id LIMIT " . (self::MAX_METERS + 1));
        $stmt->execute([(int)$id]);
        $meters = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($meters) > self::MAX_METERS) {
            throw new InvalidArgumentException(sprintf('Mais de %d relógios: confira por tipo na tela de leituras.', self::MAX_METERS));
        }
        $current = [];
        $stmt = $this->pdo->prepare('SELECT meter_id, previous_reading, consumption, entry_id FROM erp_meter_readings WHERE condominium_id = ? AND competence = ?');
        $stmt->execute([(int)$id, $competence]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $current[(int)$r['meter_id']] = $r;
        }
        $history = [];
        $stmt = $this->pdo->prepare('SELECT meter_id, consumption FROM erp_meter_readings WHERE condominium_id = ? AND competence >= ? AND competence < ?');
        $stmt->execute([(int)$id, $from, $competence]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $history[(int)$r['meter_id']][] = (float)$r['consumption'];
        }
        $last = [];
        $stmt = $this->pdo->prepare('SELECT r.meter_id, r.reading FROM erp_meter_readings r WHERE r.condominium_id = ? AND r.competence < ?
            AND r.competence = (SELECT MAX(p.competence) FROM erp_meter_readings p WHERE p.meter_id = r.meter_id AND p.competence < ?)');
        $stmt->execute([(int)$id, $competence, $competence]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $last[(int)$r['meter_id']] = (float)$r['reading'];
        }

        $summary = array_fill_keys(self::ISSUES, 0);
        $totals = ['meters' => count($meters), 'read' => 0, 'billed' => 0, 'short_history' => 0];
        $items = [];
        foreach ($meters as $m) {
            $meterId = (int)$m['id'];
            $reading = $current[$meterId] ?? null;
            if ($reading !== null) {
                $totals['read']++;
                $totals['billed'] += $reading['entry_id'] !== null ? 1 : 0;
            }
            $check = self::classify($reading === null ? null : ['previous_reading' => (float)$reading['previous_reading'], 'consumption' => (float)$reading['consumption']],
                $history[$meterId] ?? [], $last[$meterId] ?? (float)$m['initial_reading'], $factor);
            if ($check['average'] === null) {
                $totals['short_history']++;
            }
            foreach ($check['issues'] as $issue) {
                $summary[$issue]++;
            }
            if ($check['issues'] !== []) {
                $items[] = ['meter_id' => $meterId, 'kind' => (string)$m['kind'], 'number' => (string)$m['number'], 'unit_id' => $m['unit_id'] !== null ? (int)$m['unit_id'] : null,
                    'unit_label' => $m['unit_label'] !== null ? (string)$m['unit_label'] : null,
                    'consumption' => $reading !== null ? round((float)$reading['consumption'], 3) : null, 'billed' => $reading !== null && $reading['entry_id'] !== null,
                    'history_months' => count($history[$meterId] ?? [])] + $check;
            }
        }
        return ['condominium_id' => (int)$id, 'competence' => $competence, 'factor' => $factor, 'min_history' => self::MIN_HISTORY,
            'totals' => $totals + ['missing' => $totals['meters'] - $totals['read']], 'summary' => $summary, 'items' => $items];
    }
}
