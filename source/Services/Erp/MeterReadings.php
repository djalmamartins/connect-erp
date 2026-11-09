<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use PDOException;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\Audit;

/**
 * Leituras e consumo (AP-042, ADR 0045).
 *
 * - record(): leitura do relógio na competência. Consumo = leitura − leitura anterior (a da competência
 *   anterior mais recente ou a leitura inicial do relógio). Leitura menor que a anterior é recusada
 *   (troca de relógio = relógio novo). Valor pelas faixas progressivas do tipo vigentes na data da
 *   leitura: as do condomínio, se houver, senão as do padrão da Connect. Sem tarifa, valor zero.
 *   Lançar de novo a mesma competência corrige, se ela não foi cobrada e não há leitura posterior.
 * - bill(): gera uma conta a receber por leitura com valor, ainda não cobrada, da competência e do
 *   tipo, pelo RegistryService (mesmas regras dos lançamentos, inclusive a trava do fechamento).
 *   Tudo ou nada; entry_id único barra cobrar duas vezes.
 * - Escopo por condomínio (ADR-002): fora do escopo = RegistryNotFound (404). Tudo auditado.
 */
final class MeterReadings
{
    private PDO $pdo;
    private CondominiumScope $scope;
    private RegistryService $registry;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->registry = new RegistryService($this->pdo, $this->scope);
    }

    /**
     * Valor do consumo pelas faixas progressivas (regra pura): cada faixa cobra a parte do consumo
     * que cai entre "de" e "até" (vazio = sem limite), pelo preço dela. Arredonda no fim.
     *
     * @param list<array{from_amount:float|string, to_amount:float|string|null, price_cents:int|string}> $bands
     */
    public static function charge(float $consumption, array $bands): int
    {
        if ($consumption <= 0 || $bands === []) {
            return 0;
        }
        usort($bands, static fn (array $a, array $b): int => (float)$a['from_amount'] <=> (float)$b['from_amount']);
        $total = 0.0;
        foreach ($bands as $band) {
            $from = (float)$band['from_amount'];
            $to = $band['to_amount'] === null || $band['to_amount'] === '' ? INF : (float)$band['to_amount'];
            $portion = max(0.0, min($consumption, $to) - $from);
            $total += $portion * (int)$band['price_cents'];
        }
        return (int)round($total);
    }

    /** Leitura decimal ("123,456" ou 123.456) com até 3 casas, ou null se inválida. */
    public static function parseReading(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 0 && $value < 1e9 ? round((float)$value, 3) : null;
        }
        if (!is_string($value) || !preg_match('/^\d{1,9}([.,]\d{1,3})?$/', trim($value))) {
            return null;
        }
        return round((float)str_replace(',', '.', trim($value)), 3);
    }

    public function record(User $user, array $input): array
    {
        $meter = $this->meter($user, (int)filter_var($input['meter_id'] ?? 0, FILTER_VALIDATE_INT));
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        if (!RecurringEntries::isCompetence($competence)) {
            throw new InvalidArgumentException('Competência inválida. Use AAAA-MM.');
        }
        $readOn = is_string($input['read_on'] ?? null) && $input['read_on'] !== '' ? $input['read_on'] : date('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $readOn);
        if ($date === false || $date->format('Y-m-d') !== $readOn || $readOn > date('Y-m-d')) {
            throw new InvalidArgumentException('Data da leitura inválida (AAAA-MM-DD, até hoje).');
        }
        $reading = self::parseReading($input['reading'] ?? null);
        if ($reading === null) {
            throw new InvalidArgumentException('Leitura inválida (número com até 3 casas decimais).');
        }

        // Dentro da importação (ADR 0058) a transação é da importação inteira: tudo ou nada.
        $own = !$this->pdo->inTransaction();
        if ($own) {
            $this->pdo->beginTransaction();
        }
        try {
            $this->pdo->prepare('SELECT id FROM erp_meters WHERE id = :id FOR UPDATE')->execute(['id' => $meter['id']]);
            $existing = $this->pdo->prepare('SELECT * FROM erp_meter_readings WHERE meter_id = :m AND competence = :c');
            $existing->execute(['m' => $meter['id'], 'c' => $competence]);
            $before = $existing->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($before !== null && $before['entry_id'] !== null) {
                throw new DomainException("A leitura de {$competence} já foi cobrada e não pode mudar.");
            }
            $later = $this->pdo->prepare('SELECT 1 FROM erp_meter_readings WHERE meter_id = :m AND competence > :c LIMIT 1');
            $later->execute(['m' => $meter['id'], 'c' => $competence]);
            if ($later->fetchColumn()) {
                throw new DomainException('Há leitura de competência posterior. Corrija a partir da mais recente.');
            }
            $prev = $this->pdo->prepare('SELECT reading FROM erp_meter_readings WHERE meter_id = :m AND competence < :c ORDER BY competence DESC LIMIT 1');
            $prev->execute(['m' => $meter['id'], 'c' => $competence]);
            $previousValue = $prev->fetchColumn();
            $previous = $previousValue !== false ? (float)$previousValue : (float)($meter['initial_reading'] ?? 0);
            if ($reading < $previous) {
                throw new InvalidArgumentException(sprintf('Leitura menor que a anterior (%s). Se o relógio foi trocado, cadastre o relógio novo.', number_format($previous, 3, ',', '.')));
            }
            $consumption = round($reading - $previous, 3);
            [$bands, $validFrom] = $this->bands((int)$meter['condominium_id'], (string)$meter['kind'], $readOn);
            $amount = self::charge($consumption, $bands);
            $values = ['c' => $meter['condominium_id'], 'm' => $meter['id'], 'comp' => $competence, 'd' => $readOn, 'p' => number_format($previous, 3, '.', ''),
                'r' => number_format($reading, 3, '.', ''), 'q' => number_format($consumption, 3, '.', ''), 'a' => $amount, 'v' => $validFrom, 'u' => (int)$user->id];
            $this->pdo->prepare('INSERT INTO erp_meter_readings (condominium_id, meter_id, competence, read_on, previous_reading, reading, consumption, amount_cents, tariff_valid_from, created_by, updated_by)
                VALUES (:c, :m, :comp, :d, :p, :r, :q, :a, :v, :u, :u2)
                ON DUPLICATE KEY UPDATE read_on = VALUES(read_on), previous_reading = VALUES(previous_reading), reading = VALUES(reading),
                    consumption = VALUES(consumption), amount_cents = VALUES(amount_cents), tariff_valid_from = VALUES(tariff_valid_from), updated_by = VALUES(updated_by)')
                ->execute($values + ['u2' => (int)$user->id]);
            $existing->execute(['m' => $meter['id'], 'c' => $competence]);
            $after = $existing->fetch(PDO::FETCH_ASSOC);
            if ($own) {
                $this->pdo->commit();
            }
        } catch (\Throwable $error) {
            if ($own && $this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record($before === null ? 'create' : 'update', 'erp_meter_readings', (int)$after['id'],
            $before === null ? null : ['reading' => $before['reading'], 'amount_cents' => (int)$before['amount_cents']],
            ['reading' => $after['reading'], 'consumption' => $after['consumption'], 'amount_cents' => (int)$after['amount_cents']]);
        return $this->present($after + ['kind' => $meter['kind'], 'number' => $meter['number'], 'unit_id' => $meter['unit_id'], 'unit_label' => $meter['unit_label']]);
    }

    /** @return array{data:list<array>, total:int, totals:array{consumption:float, amount_cents:int}} */
    public function list(User $user, ?int $condominiumId = null, array $filters = []): array
    {
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            $ids = in_array($condominiumId, $ids, true) ? [$condominiumId] : [];
        }
        $empty = ['data' => [], 'total' => 0, 'totals' => ['consumption' => 0.0, 'amount_cents' => 0]];
        if ($ids === []) {
            return $empty;
        }
        $where = 'r.condominium_id IN (' . implode(',', $ids) . ')';
        $params = [];
        if (is_string($filters['competence'] ?? null) && RecurringEntries::isCompetence($filters['competence'])) {
            $where .= ' AND r.competence = :c';
            $params['c'] = $filters['competence'];
        }
        if (in_array($filters['kind'] ?? null, ['agua', 'gas', 'energia'], true)) {
            $where .= ' AND m.kind = :k';
            $params['k'] = $filters['kind'];
        }
        $stmt = $this->pdo->prepare("SELECT r.*, m.kind, m.number, m.unit_id, u.label AS unit_label FROM erp_meter_readings r
            JOIN erp_meters m ON m.id = r.meter_id JOIN erp_units u ON u.id = m.unit_id
            WHERE {$where} ORDER BY r.competence DESC, u.label, m.kind LIMIT 500");
        $stmt->execute($params);
        $rows = array_map(fn (array $r): array => $this->present($r), $stmt->fetchAll(PDO::FETCH_ASSOC));
        return ['data' => $rows, 'total' => count($rows), 'totals' => [
            'consumption' => round(array_sum(array_column($rows, 'consumption')), 3),
            'amount_cents' => array_sum(array_column($rows, 'amount_cents')),
        ]];
    }

    /**
     * Cobra o consumo da competência: uma conta a receber por leitura com valor e ainda não cobrada.
     *
     * @return array{competence:string, kind:string, created:list<int>, skipped:int}
     */
    public function bill(User $user, array $input): array
    {
        $condominium = (int)filter_var($input['condominium_id'] ?? 0, FILTER_VALIDATE_INT);
        if ($condominium <= 0 || !$this->scope->canAccess($user, $condominium)) {
            throw new RegistryNotFound();
        }
        $competence = is_string($input['competence'] ?? null) ? $input['competence'] : '';
        $kind = in_array($input['kind'] ?? null, ['agua', 'gas', 'energia'], true) ? $input['kind'] : null;
        $dueOn = is_string($input['due_on'] ?? null) ? $input['due_on'] : '';
        $account = (int)filter_var($input['chart_account_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!RecurringEntries::isCompetence($competence) || $kind === null) {
            throw new InvalidArgumentException('Informe a competência (AAAA-MM) e o tipo (agua, gas ou energia).');
        }
        $labels = ['agua' => 'Água', 'gas' => 'Gás', 'energia' => 'Energia'];
        $result = ['competence' => $competence, 'kind' => $kind, 'created' => [], 'skipped' => 0];

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("SELECT r.*, m.unit_id, m.number, u.label AS unit_label FROM erp_meter_readings r
                JOIN erp_meters m ON m.id = r.meter_id JOIN erp_units u ON u.id = m.unit_id
                WHERE r.condominium_id = :c AND r.competence = :comp AND m.kind = :k ORDER BY u.label, r.id FOR UPDATE");
            $stmt->execute(['c' => $condominium, 'comp' => $competence, 'k' => $kind]);
            $link = $this->pdo->prepare('UPDATE erp_meter_readings SET entry_id = :e WHERE id = :id AND entry_id IS NULL');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($row['entry_id'] !== null || (int)$row['amount_cents'] <= 0) {
                    $result['skipped']++;
                    continue;
                }
                $entry = $this->registry->save($user, 'entries', [
                    'condominium_id' => $condominium, 'nature' => 'receita', 'chart_account_id' => $account,
                    'description' => mb_substr(sprintf('%s %s — %s (%s m³)', $labels[$kind], $competence, $row['unit_label'], number_format((float)$row['consumption'], 3, ',', '.')), 0, 160),
                    'amount_cents' => number_format(((int)$row['amount_cents']) / 100, 2, ',', ''),
                    'competence' => $competence, 'due_on' => $dueOn, 'unit_id' => $row['unit_id'], 'document_number' => 'LEI-' . $row['id'],
                ]);
                $link->execute(['e' => $entry['id'], 'id' => $row['id']]);
                $result['created'][] = (int)$entry['id'];
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            if ($error instanceof PDOException && ($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('Esta competência já foi cobrada em paralelo. Atualize a lista.');
            }
            throw $error;
        }
        Audit::record('create', 'erp_meter_readings', null, null, ['billed' => count($result['created']), 'competence' => $competence, 'kind' => $kind, 'condominium_id' => $condominium]);
        return $result;
    }

    /** @return array{0: list<array>, 1: ?string} faixas vigentes e a data de vigência usada */
    private function bands(int $condominiumId, string $kind, string $readOn): array
    {
        foreach ([$condominiumId, null] as $owner) {
            $stmt = $this->pdo->prepare("SELECT MAX(valid_from) FROM erp_tariffs WHERE kind = :k AND condominium_id <=> :c AND valid_from <= :d
                AND status = 'active' AND deleted_at IS NULL");
            $stmt->execute(['k' => $kind, 'c' => $owner, 'd' => $readOn]);
            $validFrom = $stmt->fetchColumn();
            if ($validFrom) {
                $rows = $this->pdo->prepare("SELECT from_amount, to_amount, price_cents FROM erp_tariffs WHERE kind = :k AND condominium_id <=> :c
                    AND valid_from = :v AND status = 'active' AND deleted_at IS NULL");
                $rows->execute(['k' => $kind, 'c' => $owner, 'v' => $validFrom]);
                return [$rows->fetchAll(PDO::FETCH_ASSOC), (string)$validFrom];
            }
        }
        return [[], null];
    }

    private function meter(User $user, int $meterId): array
    {
        $stmt = $this->pdo->prepare("SELECT m.*, u.label AS unit_label FROM erp_meters m JOIN erp_units u ON u.id = m.unit_id
            WHERE m.id = :id AND m.deleted_at IS NULL AND m.status = 'active'");
        $stmt->execute(['id' => $meterId]);
        $meter = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($meter) || !$this->scope->canAccess($user, (int)$meter['condominium_id'])) {
            throw new RegistryNotFound();
        }
        return $meter;
    }

    private function present(array $row): array
    {
        return [
            'id' => (int)$row['id'], 'condominium_id' => (int)$row['condominium_id'], 'meter_id' => (int)$row['meter_id'],
            'kind' => (string)$row['kind'], 'meter_number' => (string)$row['number'],
            'unit_id' => (int)$row['unit_id'], 'unit_label' => (string)$row['unit_label'],
            'competence' => (string)$row['competence'], 'read_on' => (string)$row['read_on'],
            'previous_reading' => (float)$row['previous_reading'], 'reading' => (float)$row['reading'], 'consumption' => (float)$row['consumption'],
            'amount_cents' => (int)$row['amount_cents'], 'tariff_valid_from' => $row['tariff_valid_from'],
            'entry_id' => $row['entry_id'] !== null ? (int)$row['entry_id'] : null, 'billed' => $row['entry_id'] !== null,
        ];
    }
}
