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
 * Movimento da portaria do condomínio no período (ADR 0230), somente leitura. O registro de acessos (AP-133) e a
 * previsão de visita (AP-132) listam lançamento por lançamento, com nome e documento; nenhum relatório resume o
 * movimento. O síndico e a administradora querem saber, por período, quantas pessoas entraram de cada tipo, em que faixa
 * do dia, quanto tempo ficaram, quantas saídas ficaram sem registro e quantas visitas previstas aconteceram, para
 * dimensionar a portaria e cobrar o preenchimento dos registros.
 *
 * Reusa o que já existe: os tipos e as situações do cadastro (RegistryService::definition: access_logs e
 * visit_forecasts) e as mesmas regras das listas (sem os excluídos; acesso cancelado fica de fora).
 *
 * Regra pura summarize(), uma linha por tipo do cadastro (visitante, prestador, entregador, morador), sempre as quatro:
 * - entries; with_vehicle (com placa); with_unit (com unidade); from_forecast (ligado a uma visita prevista);
 * - exited (com hora de saída) e no_exit = entries - exited; timed = saídas no mesmo dia (saída ≥ entrada);
 * - stay_minutes_total e avg_stay_minutes (total ÷ timed, inteiro);
 * - by_band: entradas por faixa do dia (madrugada 0–6 h, manhã 6–12 h, tarde 12–18 h, noite 18–24 h), somando entries.
 * Os totais do topo são a soma das linhas; a média do topo sai das somas. As visitas previstas no período saem à parte:
 * total = done + cancelled + open, e overdue = as em aberto com a data (ou o fim) já passada.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * tipos e as contagens: nenhum nome, documento, placa, unidade, portaria ou observação.
 */
final class GatehouseTraffic
{
    public const MAX_DAYS = 366;
    public const MAX_ROWS = 50000;
    public const BANDS = ['madrugada' => 0, 'manha' => 6, 'tarde' => 12, 'noite' => 18];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Tipos de acesso do cadastro. @return array<string, string> */
    public static function kinds(): array
    {
        return RegistryService::definition('access_logs')['fields']['kind']['options'];
    }

    /** Faixa do dia da hora HH:MM[:SS]. */
    public static function band(string $time): string
    {
        $hour = (int)substr($time, 0, 2);
        $band = 'madrugada';
        foreach (self::BANDS as $name => $from) {
            if ($hour >= $from) {
                $band = $name;
            }
        }
        return $band;
    }

    /**
     * Resumo por tipo (regra pura).
     *
     * @param list<array{kind:string, has_unit:bool, has_plate:bool, from_forecast:bool, entered_time:string, left_time:?string}> $logs
     * @param list<array{status:string, expected_on:string, expected_until:?string}> $forecasts
     * @return array{rows:list<array>, totals:array<string, mixed>, forecasts:array<string, int>}
     */
    public static function summarize(array $logs, array $forecasts, string $today): array
    {
        $kinds = self::kinds();
        $rows = [];
        foreach ($kinds as $kind => $label) {
            $rows[$kind] = ['kind' => $kind, 'kind_label' => $label, 'entries' => 0, 'with_vehicle' => 0, 'with_unit' => 0, 'from_forecast' => 0, 'exited' => 0,
                'no_exit' => 0, 'timed' => 0, 'stay_minutes_total' => 0, 'avg_stay_minutes' => null, 'by_band' => array_fill_keys(array_keys(self::BANDS), 0)];
        }
        foreach ($logs as $log) {
            $kind = isset($rows[$log['kind']]) ? $log['kind'] : 'visitante';
            $row = &$rows[$kind];
            $row['entries']++;
            $row['with_vehicle'] += $log['has_plate'] ? 1 : 0;
            $row['with_unit'] += $log['has_unit'] ? 1 : 0;
            $row['from_forecast'] += $log['from_forecast'] ? 1 : 0;
            $row['by_band'][self::band($log['entered_time'])]++;
            $left = $log['left_time'] !== null && $log['left_time'] !== '' ? substr($log['left_time'], 0, 5) : null;
            if ($left === null) {
                $row['no_exit']++;
            } else {
                $row['exited']++;
                $entered = substr($log['entered_time'], 0, 5);
                if ($left >= $entered) {
                    $row['timed']++;
                    $row['stay_minutes_total'] += self::minutes($left) - self::minutes($entered);
                }
            }
            unset($row);
        }
        $fields = ['entries', 'with_vehicle', 'with_unit', 'from_forecast', 'exited', 'no_exit', 'timed', 'stay_minutes_total'];
        $totals = array_fill_keys($fields, 0) + ['avg_stay_minutes' => null, 'by_band' => array_fill_keys(array_keys(self::BANDS), 0)];
        foreach ($rows as &$row) {
            $row['avg_stay_minutes'] = $row['timed'] > 0 ? intdiv($row['stay_minutes_total'], $row['timed']) : null;
            foreach ($fields as $field) {
                $totals[$field] += $row[$field];
            }
            foreach (array_keys(self::BANDS) as $band) {
                $totals['by_band'][$band] += $row['by_band'][$band];
            }
        }
        unset($row);
        $totals['avg_stay_minutes'] = $totals['timed'] > 0 ? intdiv($totals['stay_minutes_total'], $totals['timed']) : null;
        $planned = ['total' => 0, 'done' => 0, 'cancelled' => 0, 'open' => 0, 'overdue' => 0];
        foreach ($forecasts as $f) {
            $planned['total']++;
            if ($f['status'] === 'done') {
                $planned['done']++;
            } elseif ($f['status'] === 'inactive') {
                $planned['cancelled']++;
            } else {
                $planned['open']++;
                $until = $f['expected_until'] !== null && $f['expected_until'] !== '' ? $f['expected_until'] : $f['expected_on'];
                $planned['overdue'] += substr($until, 0, 10) < $today ? 1 : 0;
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals, 'forecasts' => $planned];
    }

    private static function minutes(string $time): int
    {
        return (int)substr($time, 0, 2) * 60 + (int)substr($time, 3, 2);
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

        $stmt = $this->pdo->prepare("SELECT kind, unit_id IS NOT NULL AS has_unit, plate IS NOT NULL AND plate <> '' AS has_plate,
                visit_forecast_id IS NOT NULL AS from_forecast, entered_time, left_time
            FROM erp_access_logs WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active' AND entered_on BETWEEN ? AND ?
            ORDER BY id LIMIT " . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($logs) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d acessos no período: escolha um período menor.', self::MAX_ROWS));
        }
        $stmt = $this->pdo->prepare('SELECT status, expected_on, expected_until FROM erp_visit_forecasts WHERE condominium_id = ? AND deleted_at IS NULL
            AND expected_on BETWEEN ? AND ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $forecasts = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($forecasts) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d visitas previstas no período: escolha um período menor.', self::MAX_ROWS));
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize(
            array_map(static fn (array $l): array => ['kind' => (string)$l['kind'], 'has_unit' => (bool)$l['has_unit'], 'has_plate' => (bool)$l['has_plate'],
                'from_forecast' => (bool)$l['from_forecast'], 'entered_time' => (string)$l['entered_time'],
                'left_time' => $l['left_time'] !== null ? (string)$l['left_time'] : null], $logs),
            array_map(static fn (array $f): array => ['status' => (string)$f['status'], 'expected_on' => substr((string)$f['expected_on'], 0, 10),
                'expected_until' => $f['expected_until'] !== null ? substr((string)$f['expected_until'], 0, 10) : null], $forecasts),
            date('Y-m-d'));
    }
}
