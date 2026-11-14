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
 * Histórico das assembleias do condomínio num período (ADR 0264), somente leitura. As assembleias (AP-018/088/017, ADR
 * 0088) têm o fluxo de cada uma (convocar, abrir, presença, votação, ata) e a conferência antes da assembleia (ADR 0211);
 * cada assembleia mostra a sua presença e a sua apuração. Ninguém responde, porém, como foram as assembleias de um
 * período: quantas ordinárias e extraordinárias, quantas encerradas, canceladas ou ainda em andamento, quantas unidades
 * compareceram em média, quantas por procuração, quantas sem direito a voto por estarem em atraso, e quantos itens
 * foram aprovados ou rejeitados. O síndico usa isso na prestação de contas e para planejar a próxima convocação (por
 * exemplo, quando a presença é baixa, a assembleia virtual).
 *
 * Reusa o que já existe: os tipos das assembleias (Assemblies::KINDS), as situações do fluxo e o resultado da apuração
 * gravado no item (Assemblies::decide, result_json.approved), sem recalcular nada.
 *
 * Regra pura summarize(), uma linha por tipo, sempre os dois, com as assembleias de primeira convocação no período:
 * - assemblies: todas; closed, cancelled e pending (rascunho, convocada ou aberta);
 * - attended_units: unidades presentes, somadas nas encerradas (cada unidade conta uma vez por assembleia);
 *   avg_attended_units: a média por assembleia encerrada (null sem encerrada);
 * - proxies e ineligible: presenças por procuração e sem direito a voto (inadimplente na abertura), nas encerradas;
 * - items_decided, items_approved e items_rejected: itens apurados, aprovados e não aprovados (das não canceladas).
 * totals soma as contagens das linhas (avg_attended_units recalculada sobre o total); last_closed_on é a data da última
 * assembleia encerrada no período (null sem nenhuma).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só os
 * tipos, as contagens, as médias e a data: nenhum título, local, pauta, ata, presente, unidade ou voto.
 */
final class AssemblyHistory
{
    public const MAX_DAYS = 3660;
    public const MAX_ROWS = 5000;
    public const COUNTS = ['assemblies', 'closed', 'cancelled', 'pending', 'attended_units', 'proxies', 'ineligible', 'items_decided', 'items_approved', 'items_rejected'];
    public const LABELS = ['ordinaria' => 'Ordinária', 'extraordinaria' => 'Extraordinária'];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /**
     * Resumo (regra pura).
     *
     * @param list<array{id:int, kind:string, status:string, closed_on:?string}> $assemblies
     * @param list<array{assembly_id:int, unit_id:int, is_proxy:bool, eligible:bool}> $attendance
     * @param list<array{assembly_id:int, status:string, approved:?bool}> $items
     */
    public static function summarize(array $assemblies, array $attendance, array $items): array
    {
        $rows = [];
        foreach (Assemblies::KINDS as $kind) {
            $rows[$kind] = ['kind' => $kind, 'kind_label' => self::LABELS[$kind] ?? $kind] + array_fill_keys(self::COUNTS, 0) + ['avg_attended_units' => null];
        }
        $byId = [];
        $lastClosed = null;
        foreach ($assemblies as $a) {
            $kind = isset($rows[$a['kind']]) ? $a['kind'] : Assemblies::KINDS[0];
            $byId[$a['id']] = ['kind' => $kind, 'status' => $a['status']];
            $rows[$kind]['assemblies']++;
            $rows[$kind][match ($a['status']) {
                'closed' => 'closed',
                'cancelled' => 'cancelled',
                default => 'pending',
            }]++;
            if ($a['status'] === 'closed' && $a['closed_on'] !== null && ($lastClosed === null || $a['closed_on'] > $lastClosed)) {
                $lastClosed = $a['closed_on'];
            }
        }
        $seen = [];
        foreach ($attendance as $p) {
            $a = $byId[$p['assembly_id']] ?? null;
            if ($a === null || $a['status'] !== 'closed') {
                continue;
            }
            if (!isset($seen[$p['assembly_id']][$p['unit_id']])) {
                $seen[$p['assembly_id']][$p['unit_id']] = true;
                $rows[$a['kind']]['attended_units']++;
            }
            if ($p['is_proxy']) {
                $rows[$a['kind']]['proxies']++;
            }
            if (!$p['eligible']) {
                $rows[$a['kind']]['ineligible']++;
            }
        }
        foreach ($items as $i) {
            $a = $byId[$i['assembly_id']] ?? null;
            if ($a === null || $a['status'] === 'cancelled' || $i['status'] !== 'decided') {
                continue;
            }
            $rows[$a['kind']]['items_decided']++;
            $rows[$a['kind']][$i['approved'] === true ? 'items_approved' : 'items_rejected']++;
        }
        $average = static fn (int $units, int $closed): ?float => $closed === 0 ? null : round($units / $closed, 1);
        foreach ($rows as $kind => $row) {
            $rows[$kind]['avg_attended_units'] = $average($row['attended_units'], $row['closed']);
        }
        $totals = [];
        foreach (self::COUNTS as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }
        $totals['avg_attended_units'] = $average($totals['attended_units'], $totals['closed']);
        return ['rows' => array_values($rows), 'totals' => $totals, 'last_closed_on' => $lastClosed];
    }

    public function build(User $user, mixed $condominiumId, mixed $from = null, mixed $to = null): array
    {
        $id = is_int($condominiumId) || is_string($condominiumId) ? filter_var($condominiumId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        if ($id === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = new DateTimeImmutable('today');
        $parse = static function (mixed $value, DateTimeImmutable $default, string $label): string {
            if ($value === null || $value === '') {
                return $default->format('Y-m-d');
            }
            $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value) : false;
            if ($date === false || $date->format('Y-m-d') !== $value) {
                throw new InvalidArgumentException($label . ': AAAA-MM-DD.');
            }
            return $value;
        };
        $from = $parse($from, $today->modify('-730 days'), 'Data inicial');
        $to = $parse($to, $today->modify('+365 days'), 'Data final');
        if ($to < $from || (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período: a data final depois da inicial, até %d dias.', self::MAX_DAYS));
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, kind, status, closed_at FROM erp_assemblies WHERE condominium_id = ? AND first_call_at >= ? AND first_call_at < ?
            ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from . ' 00:00:00', (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00']);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d assembleias: escolha um período menor.', self::MAX_ROWS));
        }
        $assemblies = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'kind' => (string)$r['kind'], 'status' => (string)$r['status'],
            'closed_on' => $r['closed_at'] === null ? null : substr((string)$r['closed_at'], 0, 10)], $rows);
        $attendance = [];
        $items = [];
        $ids = array_column($assemblies, 'id');
        if ($ids !== []) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare("SELECT assembly_id, unit_id, is_proxy, eligible FROM erp_assembly_attendance WHERE assembly_id IN ({$in})");
            $stmt->execute($ids);
            $attendance = array_map(static fn (array $r): array => ['assembly_id' => (int)$r['assembly_id'], 'unit_id' => (int)$r['unit_id'],
                'is_proxy' => (int)$r['is_proxy'] === 1, 'eligible' => (int)$r['eligible'] === 1], $stmt->fetchAll(PDO::FETCH_ASSOC));
            $stmt = $this->pdo->prepare("SELECT assembly_id, status, result_json FROM erp_assembly_items WHERE assembly_id IN ({$in})");
            $stmt->execute($ids);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $result = $r['result_json'] === null ? null : json_decode((string)$r['result_json'], true);
                $items[] = ['assembly_id' => (int)$r['assembly_id'], 'status' => (string)$r['status'],
                    'approved' => is_array($result) && array_key_exists('approved', $result) ? (bool)$result['approved'] : null];
            }
        }
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to] + self::summarize($assemblies, $attendance, $items);
    }
}
