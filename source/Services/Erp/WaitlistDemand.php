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
 * Procura pelas áreas comuns na lista de espera (ADR 0260), somente leitura. A lista de espera (AP-115) guarda quem quer
 * uma área numa data já reservada; o painel operacional chama o próximo quando a reserva cai e a baixa em lote (ADR 0229)
 * encerra as inscrições de datas que passaram. Ninguém responde, porém, quais áreas têm mais procura do que comportam:
 * quantas inscrições esperam por área, em quantas datas, quantas unidades diferentes, quantas já foram chamadas e
 * quantas foram atendidas ou desistiram. O síndico usa isso para decidir horário estendido, um segundo turno ou a
 * reforma da churrasqueira disputada.
 *
 * Reusa o que já existe: as áreas do cadastro (erp_common_areas, sem as excluídas) e as situações da lista de espera
 * (active = aguardando, served = atendida, inactive = desistiu), sem as inscrições excluídas.
 *
 * Regra pura summarize(), uma linha por área do condomínio (e, se houver, uma linha "Área excluída" para as inscrições de
 * área excluída), com as inscrições de data desejada entre from e to:
 * - waiting_upcoming e waiting_past: aguardando com a data em today ou depois, e antes de today (a encerrar pelo ADR 0229);
 * - called: das aguardando, as já chamadas;
 * - served e gave_up: atendidas e desistências;
 * - entries: todas; units: unidades diferentes; dates_upcoming: datas diferentes com alguém aguardando de today em diante;
 * - next_desired_on: a próxima data com alguém aguardando (null = nenhuma).
 * totals soma as contagens das linhas (units soma as unidades de cada área).
 *
 * Não grava e não envia nada. Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem só a
 * área (id, nome, situação), as contagens e as datas: nenhum morador, unidade ou observação.
 */
final class WaitlistDemand
{
    public const MAX_ROWS = 50000;
    public const MAX_DAYS = 1100;
    public const KEYS = ['waiting_upcoming', 'waiting_past', 'called', 'served', 'gave_up', 'entries', 'units', 'dates_upcoming'];

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
     * @param list<array{id:int, name:string, status:string}> $areas
     * @param list<array{area_id:int, unit_id:int, desired_on:string, status:string, called:bool}> $entries
     */
    public static function summarize(array $areas, array $entries, string $today): array
    {
        $rows = [];
        foreach ($areas as $a) {
            $rows[$a['id']] = ['area_id' => $a['id'], 'area_name' => $a['name'], 'area_status' => $a['status']] + array_fill_keys(self::KEYS, 0) + ['next_desired_on' => null];
        }
        $units = [];
        $dates = [];
        foreach ($entries as $e) {
            $key = isset($rows[$e['area_id']]) ? $e['area_id'] : 0;
            if (!isset($rows[$key])) {
                $rows[$key] = ['area_id' => null, 'area_name' => 'Área excluída', 'area_status' => 'deleted'] + array_fill_keys(self::KEYS, 0) + ['next_desired_on' => null];
            }
            $date = substr($e['desired_on'], 0, 10);
            $rows[$key]['entries']++;
            $units[$key][$e['unit_id']] = true;
            if ($e['status'] === 'served') {
                $rows[$key]['served']++;
            } elseif ($e['status'] === 'inactive') {
                $rows[$key]['gave_up']++;
            } else {
                $rows[$key][$date >= $today ? 'waiting_upcoming' : 'waiting_past']++;
                if ($e['called']) {
                    $rows[$key]['called']++;
                }
                if ($date >= $today) {
                    $dates[$key][$date] = true;
                    if ($rows[$key]['next_desired_on'] === null || $date < $rows[$key]['next_desired_on']) {
                        $rows[$key]['next_desired_on'] = $date;
                    }
                }
            }
        }
        foreach (array_keys($rows) as $key) {
            $rows[$key]['units'] = count($units[$key] ?? []);
            $rows[$key]['dates_upcoming'] = count($dates[$key] ?? []);
        }
        $totals = array_fill_keys(self::KEYS, 0);
        foreach ($rows as $row) {
            foreach (self::KEYS as $k) {
                $totals[$k] += $row[$k];
            }
        }
        return ['rows' => array_values($rows), 'totals' => $totals];
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
        $from = $parse($from, $today->modify('-365 days'), 'Data inicial');
        $to = $parse($to, $today->modify('+365 days'), 'Data final');
        if ($to < $from || (int)(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days > self::MAX_DAYS) {
            throw new InvalidArgumentException(sprintf('Período: a data final depois da inicial, até %d dias.', self::MAX_DAYS));
        }
        $this->scope->assertAccess($user, (int)$id);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT id, name, status FROM erp_common_areas WHERE condominium_id = ? AND deleted_at IS NULL ORDER BY name, id');
        $stmt->execute([(int)$id]);
        $areas = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'status' => (string)$r['status']],
            $stmt->fetchAll(PDO::FETCH_ASSOC));
        $stmt = $this->pdo->prepare('SELECT common_area_id, unit_id, desired_on, status, CASE WHEN called_at IS NULL THEN 0 ELSE 1 END AS called FROM erp_waitlist
            WHERE condominium_id = ? AND deleted_at IS NULL AND desired_on BETWEEN ? AND ? ORDER BY id LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute([(int)$id, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('Mais de %d inscrições: escolha um período menor.', self::MAX_ROWS));
        }
        $entries = array_map(static fn (array $r): array => ['area_id' => (int)$r['common_area_id'], 'unit_id' => (int)$r['unit_id'],
            'desired_on' => substr((string)$r['desired_on'], 0, 10), 'status' => (string)$r['status'], 'called' => (int)$r['called'] === 1], $rows);
        return ['condominium_id' => (int)$id, 'from' => $from, 'to' => $to, 'today' => $today->format('Y-m-d')]
            + self::summarize($areas, $entries, $today->format('Y-m-d'));
    }
}
