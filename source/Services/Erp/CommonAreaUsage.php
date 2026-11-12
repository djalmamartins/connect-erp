<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\Definitions;

/**
 * Uso dos espaços comuns do condomínio no período (ADR 0228), somente leitura. O relatório de reservas
 * (RegistryReports::reservations) lista reserva por reserva, com morador e unidade; o resumo operacional
 * (OperationsSummary) conta só as confirmadas do mês. O síndico e o conselho querem saber, por área e período, quanto cada
 * espaço foi usado, quantos pedidos ficaram esperando ou foram cancelados, quanto entrou de taxa e em que dias da semana o
 * uso se concentra, para revisar regras, taxas e horários das áreas.
 *
 * Reusa o que já existe: os rótulos do cadastro (Definitions: modo de reserva das áreas comuns e situação das reservas) e
 * as mesmas regras do relatório de reservas (sem as excluídas; a taxa só conta nas confirmadas).
 *
 * Regra pura summarize(), uma linha por área (as ativas, mesmo sem reserva, e as inativas que tiveram reserva no período):
 * - reservations = confirmed + pending (aguardando aprovação) + cancelled;
 * - fee_cents: a taxa das confirmadas;
 * - days_used: dias distintos com reserva confirmada; occupancy_pct = days_used ÷ dias do período (1 casa);
 * - units: quantas unidades distintas usaram a área (só a contagem); cancel_pct = cancelled ÷ reservations (1 casa);
 * - by_weekday: as confirmadas por dia da semana (seg a dom), somando confirmed.
 * Os totais do topo são a soma das linhas (days_used vira dias-área); os percentuais do topo saem das somas.
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só as
 * áreas e as somas: nenhum morador, unidade, horário ou observação.
 */
final class CommonAreaUsage
{
    public const MAX_DAYS = 366;
    public const MAX_RESERVATIONS = 20000;
    public const WEEKDAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo por área (regra pura).
     *
     * @param list<array{id:int, name:string, booking_mode:string, status:string}> $areas
     * @param list<array{common_area_id:int, unit_id:int, reserved_on:string, status:string, fee_cents:?int}> $reservations
     * @return array{period_days:int, rows:list<array>, totals:array<string, mixed>}
     */
    public static function summarize(array $areas, array $reservations, string $from, string $to): array
    {
        $periodDays = (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days + 1;
        $modes = Definitions::get('common_areas')['fields']['booking_mode']['options'] ?? [];
        $byArea = [];
        foreach ($reservations as $r) {
            $byArea[(int)$r['common_area_id']][] = $r;
        }
        $rows = [];
        foreach ($areas as $area) {
            $id = (int)$area['id'];
            $list = $byArea[$id] ?? [];
            if ($list === [] && $area['status'] !== 'active') {
                continue;
            }
            $row = ['area_id' => $id, 'name' => (string)$area['name'], 'booking_mode' => (string)$area['booking_mode'],
                'booking_mode_label' => $modes[$area['booking_mode']] ?? (string)$area['booking_mode'], 'area_active' => $area['status'] === 'active',
                'reservations' => 0, 'confirmed' => 0, 'pending' => 0, 'cancelled' => 0, 'fee_cents' => 0, 'days_used' => 0, 'occupancy_pct' => 0.0,
                'units' => 0, 'cancel_pct' => null, 'by_weekday' => array_fill_keys(self::WEEKDAYS, 0)];
            $days = [];
            $units = [];
            foreach ($list as $r) {
                $row['reservations']++;
                match ((string)$r['status']) {
                    'confirmed' => $row['confirmed']++,
                    'inactive' => $row['cancelled']++,
                    default => $row['pending']++,
                };
                if ($r['status'] !== 'confirmed') {
                    continue;
                }
                $row['fee_cents'] += (int)($r['fee_cents'] ?? 0);
                $days[(string)$r['reserved_on']] = true;
                $units[(int)$r['unit_id']] = true;
                $row['by_weekday'][self::WEEKDAYS[(int)(new DateTimeImmutable((string)$r['reserved_on']))->format('N') - 1]]++;
            }
            $row['days_used'] = count($days);
            $row['units'] = count($units);
            $row['occupancy_pct'] = round(100 * $row['days_used'] / $periodDays, 1);
            $row['cancel_pct'] = $row['reservations'] > 0 ? round(100 * $row['cancelled'] / $row['reservations'], 1) : null;
            $rows[] = $row;
        }
        usort($rows, static fn (array $a, array $b): int => [$b['confirmed'], $a['name'], $a['area_id']] <=> [$a['confirmed'], $b['name'], $b['area_id']]);
        $fields = ['reservations', 'confirmed', 'pending', 'cancelled', 'fee_cents', 'days_used'];
        $totals = ['areas' => count($rows)] + array_fill_keys($fields, 0) + ['cancel_pct' => null, 'by_weekday' => array_fill_keys(self::WEEKDAYS, 0)];
        foreach ($rows as $row) {
            foreach ($fields as $field) {
                $totals[$field] += $row[$field];
            }
            foreach (self::WEEKDAYS as $day) {
                $totals['by_weekday'][$day] += $row['by_weekday'][$day];
            }
        }
        $totals['cancel_pct'] = $totals['reservations'] > 0 ? round(100 * $totals['cancelled'] / $totals['reservations'], 1) : null;
        return ['period_days' => $periodDays, 'rows' => $rows, 'totals' => $totals];
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

        $stmt = $this->pdo->prepare('SELECT id, name, booking_mode, status FROM erp_common_areas WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY name, id');
        $stmt->execute([(int)$id]);
        $areas = array_map(static fn (array $a): array => ['id' => (int)$a['id'], 'name' => (string)$a['name'], 'booking_mode' => (string)$a['booking_mode'],
            'status' => (string)$a['status']], $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare('SELECT common_area_id, unit_id, reserved_on, status, fee_cents FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL
            AND reserved_on BETWEEN ? AND ? ORDER BY id LIMIT ' . (self::MAX_RESERVATIONS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($reservations) > self::MAX_RESERVATIONS) {
            throw new InvalidArgumentException(sprintf('Mais de %d reservas no período: escolha um período menor.', self::MAX_RESERVATIONS));
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize($areas, array_map(static fn (array $r): array => [
            'common_area_id' => (int)$r['common_area_id'], 'unit_id' => (int)$r['unit_id'], 'reserved_on' => substr((string)$r['reserved_on'], 0, 10),
            'status' => (string)$r['status'], 'fee_cents' => $r['fee_cents'] !== null ? (int)$r['fee_cents'] : null], $reservations), $from, $to);
    }
}
