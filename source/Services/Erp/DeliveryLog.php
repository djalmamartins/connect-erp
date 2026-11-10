<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Support\Audit;

/**
 * Registros de Entregas (AP-136, ADR 0166): o histórico das encomendas da portaria e a baixa da retirada.
 *
 * As encomendas ficam em erp_deliveries (migration 20261108_0525). A chegada é registrada pelo cadastro
 * de Entregas (AP-135, motor de cadastros: POST /api/v1/registries/deliveries). Este service lista com
 * filtros e resumo e marca a retirada (quem e quando). Não guarda foto nem documento de quem retira:
 * só o nome livre. Quem recebeu é o created_by do registro.
 *
 * Escopo (ADR-002): um condomínio (fora = CondominiumAccessDenied, 404) ou todos os do escopo.
 */
final class DeliveryLog
{
    public const STATUSES = ['active', 'picked_up', 'inactive'];
    public const MAX_ROWS = 5000;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Filtros validados (regra pura). Período opcional em received_on, até 366 dias. */
    public static function filters(array $input): array
    {
        $status = $input['status'] ?? null;
        if ($status !== null && $status !== '' && !in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Situação inválida. Use: ' . implode(', ', self::STATUSES) . '.');
        }
        $from = $input['from'] ?? null;
        $to = $input['to'] ?? null;
        $period = null;
        if (($from ?? '') !== '' || ($to ?? '') !== '') {
            $period = AccountingReports::period($from, $to);
        }
        $unit = filter_var($input['unit_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return ['status' => $status === '' ? null : $status, 'from' => $period[0] ?? null, 'to' => $period[1] ?? null, 'unit_id' => $unit === false || $unit === null ? null : (int)$unit];
    }

    /**
     * Resumo (regra pura): quantas aguardam, foram retiradas e canceladas; média de dias até a retirada e
     * a espera mais antiga entre as que aguardam, em $today.
     *
     * @param list<array{status:string, received_on:string, picked_up_on:?string}> $rows
     */
    public static function summary(array $rows, string $today): array
    {
        $count = ['active' => 0, 'picked_up' => 0, 'inactive' => 0];
        $pickupDays = [];
        $oldest = 0;
        foreach ($rows as $row) {
            $count[$row['status']] = ($count[$row['status']] ?? 0) + 1;
            if ($row['status'] === 'picked_up' && !empty($row['picked_up_on'])) {
                $pickupDays[] = self::days((string)$row['received_on'], (string)$row['picked_up_on']);
            }
            if ($row['status'] === 'active') {
                $oldest = max($oldest, self::days((string)$row['received_on'], $today));
            }
        }
        return ['waiting' => $count['active'], 'picked_up' => $count['picked_up'], 'cancelled' => $count['inactive'],
            'average_pickup_days' => $pickupDays === [] ? null : round(array_sum($pickupDays) / count($pickupDays), 1), 'oldest_waiting_days' => $oldest];
    }

    public function log(User $user, ?int $condominiumId, array $input, ?string $today = null): array
    {
        $filters = self::filters($input);
        $today ??= date('Y-m-d');
        $ids = $this->ids($user, $condominiumId);
        $base = ['condominium_id' => $condominiumId] + $filters;
        if ($ids === []) {
            return $base + ['truncated' => false, 'rows' => [], 'summary' => self::summary([], $today)];
        }
        $where = ['d.condominium_id IN (' . implode(',', $ids) . ')', 'd.deleted_at IS NULL'];
        $params = [];
        foreach (['status' => 'd.status = :status', 'unit_id' => 'd.unit_id = :unit_id'] as $key => $sql) {
            if ($filters[$key] !== null) {
                $where[] = $sql;
                $params[$key] = $filters[$key];
            }
        }
        if ($filters['from'] !== null) {
            $where[] = 'd.received_on BETWEEN :from AND :to';
            $params += ['from' => $filters['from'], 'to' => $filters['to']];
        }
        $stmt = $this->pdo->prepare('SELECT d.id, d.condominium_id, c.name AS condominium_name, d.unit_id, u.label AS unit_label, d.recipient, d.carrier,
                d.tracking_code, d.received_on, d.received_time, d.created_by, d.picked_up_on, d.picked_up_by, d.status
            FROM erp_deliveries d JOIN condominiums c ON c.id = d.condominium_id LEFT JOIN erp_units u ON u.id = d.unit_id
            WHERE ' . implode(' AND ', $where) . ' ORDER BY d.received_on DESC, d.id DESC LIMIT ' . (self::MAX_ROWS + 1));
        $stmt->execute($params);
        $raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $truncated = count($raw) > self::MAX_ROWS;
        $rows = array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'condominium_id' => (int)$r['condominium_id'], 'condominium_name' => (string)$r['condominium_name'],
            'unit_id' => (int)$r['unit_id'], 'unit_label' => (string)($r['unit_label'] ?? ''), 'recipient' => (string)$r['recipient'], 'carrier' => (string)$r['carrier'],
            'tracking_code' => $r['tracking_code'], 'received_on' => (string)$r['received_on'], 'received_time' => $r['received_time'],
            'received_by_user_id' => $r['created_by'] !== null ? (int)$r['created_by'] : null, 'picked_up_on' => $r['picked_up_on'], 'picked_up_by' => $r['picked_up_by'],
            'status' => (string)$r['status'],
            'waiting_days' => $r['status'] === 'active' ? self::days((string)$r['received_on'], $today) : null], array_slice($raw, 0, self::MAX_ROWS));
        return $base + ['truncated' => $truncated, 'rows' => $rows, 'summary' => self::summary($rows, $today)];
    }

    /**
     * Baixa da retirada: só encomenda aguardando (status active), com o nome de quem retirou e a data
     * (padrão hoje; entre a chegada e hoje). Encomenda de outro condomínio = 404; já retirada ou cancelada = 409.
     */
    public function pickUp(User $user, int $deliveryId, mixed $pickedUpBy, mixed $pickedUpOn = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $stmt = $this->pdo->prepare('SELECT id, condominium_id, received_on, status, picked_up_on FROM erp_deliveries WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $deliveryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || !$this->scope->canAccess($user, (int)$row['condominium_id'])) {
            throw new CondominiumAccessDenied($row === false ? 0 : (int)$row['condominium_id']);   // 404, sem dizer se existe
        }
        if ($row['status'] !== 'active') {
            throw new DomainException('Esta entrega já foi retirada ou cancelada.');
        }
        $name = is_string($pickedUpBy) ? trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $pickedUpBy)) : '';
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw new InvalidArgumentException('Informe quem retirou a entrega (2 a 160 caracteres).');
        }
        $on = $pickedUpOn === null || $pickedUpOn === '' ? $today : $pickedUpOn;
        $date = is_string($on) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $on) : false;
        if ($date === false || $date->format('Y-m-d') !== $on || $on < (string)$row['received_on'] || $on > $today) {
            throw new InvalidArgumentException('Data da retirada inválida (AAAA-MM-DD, entre a chegada e hoje).');
        }
        $update = $this->pdo->prepare("UPDATE erp_deliveries SET status = 'picked_up', picked_up_on = :on, picked_up_by = :by, updated_by = :u
            WHERE id = :id AND status = 'active' AND deleted_at IS NULL");
        $update->execute(['on' => $on, 'by' => $name, 'u' => (int)$user->id, 'id' => $deliveryId]);
        if ($update->rowCount() !== 1) {
            throw new DomainException('Esta entrega já foi retirada ou cancelada.');
        }
        // O nome de quem retirou fica só no registro; a auditoria guarda a mudança de situação e a data.
        Audit::record('update', 'erp_deliveries', $deliveryId, ['status' => 'active', 'picked_up_on' => $row['picked_up_on']],
            ['status' => 'picked_up', 'picked_up_on' => $on, 'condominium_id' => (int)$row['condominium_id']]);
        return ['id' => $deliveryId, 'condominium_id' => (int)$row['condominium_id'], 'status' => 'picked_up', 'picked_up_on' => $on, 'picked_up_by' => $name];
    }

    private static function days(string $from, string $to): int
    {
        $a = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($from, 0, 10));
        $b = \DateTimeImmutable::createFromFormat('!Y-m-d', substr($to, 0, 10));
        if ($a === false || $b === false || $b < $a) {
            return 0;
        }
        return (int)$a->diff($b)->days;
    }

    /** @return list<int> */
    private function ids(User $user, ?int $condominiumId): array
    {
        if ($condominiumId !== null) {
            $this->scope->assertAccess($user, $condominiumId);
            return [$condominiumId];
        }
        return array_values(array_filter(array_map('intval', $this->scope->allowedIds($user)), static fn (int $id): bool => $id > 0));
    }
}
