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
use Source\Support\Audit;

/**
 * Dashboard da administradora e continuações do Operacional (ADR 0053).
 *
 * - summary(): números e alertas reais no contexto (um condomínio ou todos do escopo):
 *   manutenções atrasadas e da semana, seguros e contratos a renovar (30 dias) ou vencidos,
 *   reservas aguardando aprovação, ocorrências abertas, entregas aguardando retirada, inadimplência,
 *   contas a pagar e a receber dos próximos 7 dias e reservas canceladas com lista de espera.
 * - callWaitlist(): ação explícita que chama o primeiro da lista de espera de uma reserva cancelada.
 *   Só registra a chamada (quem, quando, para qual reserva); nunca cria a reserva sozinho.
 * - nextMaintenance(): gera a próxima manutenção de uma concluída pela periodicidade, uma vez só.
 * - Escopo por condomínio (ADR-002); ações auditadas.
 */
final class OperationalDashboard
{
    public const RENEWAL_DAYS = 30;
    public const FREQUENCY_MONTHS = ['mensal' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Situação de vigência (regra pura): vigente, a_renovar (até 30 dias para vencer) ou vencido; sem fim = vigente. */
    public static function validity(?string $endsOn, string $today, int $window = self::RENEWAL_DAYS): string
    {
        if ($endsOn === null || $endsOn === '') {
            return 'vigente';
        }
        if ($endsOn < $today) {
            return 'vencido';
        }
        $limit = (new \DateTimeImmutable($today))->modify("+{$window} days")->format('Y-m-d');
        return $endsOn <= $limit ? 'a_renovar' : 'vigente';
    }

    /** Manutenção atrasada (regra pura): agendada com data prevista antes de hoje. */
    public static function isLate(string $status, string $plannedOn, string $today): bool
    {
        return $status === 'active' && $plannedOn < $today;
    }

    /** Próxima data pela periodicidade (regra pura), com o dia limitado ao fim do mês; única = null. */
    public static function nextDate(string $plannedOn, string $frequency): ?string
    {
        $months = self::FREQUENCY_MONTHS[$frequency] ?? null;
        if ($months === null) {
            return null;
        }
        $date = new \DateTimeImmutable($plannedOn);
        $day = (int)$date->format('d');
        $first = $date->modify('first day of +' . $months . ' month');
        return $first->setDate((int)$first->format('Y'), (int)$first->format('m'), min($day, (int)$first->format('t')))->format('Y-m-d');
    }

    /** @return array{as_of:string, condominium_id:?int, cards:array<string,int>, alerts:list<array>} */
    public function summary(User $user, ?int $condominiumId = null, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $ids = array_map('intval', $this->scope->allowedIds($user));
        if ($condominiumId !== null) {
            if (!in_array($condominiumId, $ids, true)) {
                throw new RegistryNotFound();
            }
            $ids = [$condominiumId];
        }
        $empty = ['as_of' => $today, 'condominium_id' => $condominiumId, 'cards' => [], 'alerts' => []];
        if ($ids === []) {
            return $empty;
        }
        $in = implode(',', $ids);
        $week = (new \DateTimeImmutable($today))->modify('+7 days')->format('Y-m-d');
        $renew = (new \DateTimeImmutable($today))->modify('+' . self::RENEWAL_DAYS . ' days')->format('Y-m-d');
        $count = function (string $sql, array $params = []): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };
        $sum = function (string $sql, array $params = []): int {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        };
        $cards = [
            'maintenances_late' => $count("SELECT COUNT(*) FROM erp_maintenances WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND planned_on < :t", ['t' => $today]),
            'maintenances_week' => $count("SELECT COUNT(*) FROM erp_maintenances WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND planned_on BETWEEN :t AND :w", ['t' => $today, 'w' => $week]),
            'insurances_renewal' => $count("SELECT COUNT(*) FROM erp_insurances WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND ends_on BETWEEN :t AND :r", ['t' => $today, 'r' => $renew]),
            'insurances_expired' => $count("SELECT COUNT(*) FROM erp_insurances WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND ends_on < :t", ['t' => $today]),
            'contracts_renewal' => $count("SELECT COUNT(*) FROM erp_contracts WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND ends_on BETWEEN :t AND :r", ['t' => $today, 'r' => $renew]),
            'contracts_expired' => $count("SELECT COUNT(*) FROM erp_contracts WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND ends_on < :t", ['t' => $today]),
            'reservations_pending' => $count("SELECT COUNT(*) FROM erp_reservations WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active' AND reserved_on >= :t", ['t' => $today]),
            'occurrences_open' => $count("SELECT COUNT(*) FROM erp_occurrences WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status IN ('active','running')"),
            'deliveries_waiting' => $count("SELECT COUNT(*) FROM erp_deliveries WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'"),
            'receivables_overdue_cents' => $sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'
                AND nature = 'receita' AND paid_on IS NULL AND due_on < :t", ['t' => $today]),
            'receivables_week_cents' => $sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'
                AND nature = 'receita' AND paid_on IS NULL AND due_on BETWEEN :t AND :w", ['t' => $today, 'w' => $week]),
            'payables_week_cents' => $sum("SELECT COALESCE(SUM(amount_cents), 0) FROM erp_entries WHERE condominium_id IN ({$in}) AND deleted_at IS NULL AND status = 'active'
                AND nature = 'despesa' AND paid_on IS NULL AND due_on BETWEEN :t AND :w", ['t' => $today, 'w' => $week]),
        ];
        $cards['waitlist_to_call'] = count($this->waitlistCandidates($in, $today));

        $alerts = [];
        $add = static function (array &$alerts, string $kind, string $severity, int $count, string $message, string $route): void {
            if ($count > 0) {
                $alerts[] = ['kind' => $kind, 'severity' => $severity, 'count' => $count, 'message' => $message, 'route' => $route];
            }
        };
        $add($alerts, 'maintenances_late', 'danger', $cards['maintenances_late'], 'Manutenções atrasadas', '/operacional/manutencoes');
        $add($alerts, 'insurances_expired', 'danger', $cards['insurances_expired'], 'Seguros vencidos', '/operacional/seguros');
        $add($alerts, 'contracts_expired', 'danger', $cards['contracts_expired'], 'Contratos vencidos', '/operacional/contratos');
        $add($alerts, 'insurances_renewal', 'warning', $cards['insurances_renewal'], 'Seguros a renovar em até 30 dias', '/operacional/seguros');
        $add($alerts, 'contracts_renewal', 'warning', $cards['contracts_renewal'], 'Contratos a renovar em até 30 dias', '/operacional/contratos');
        $add($alerts, 'waitlist_to_call', 'warning', $cards['waitlist_to_call'], 'Reservas canceladas com lista de espera para chamar', '/operacional/lista-de-espera');
        $add($alerts, 'reservations_pending', 'info', $cards['reservations_pending'], 'Reservas aguardando aprovação', '/operacional/agendamentos-pendentes');
        $add($alerts, 'occurrences_open', 'info', $cards['occurrences_open'], 'Ocorrências abertas', '/operacional/ocorrencias');
        $add($alerts, 'deliveries_waiting', 'info', $cards['deliveries_waiting'], 'Entregas aguardando retirada', '/operacional/entregas');
        return ['as_of' => $today, 'condominium_id' => $condominiumId, 'cards' => $cards, 'alerts' => $alerts];
    }

    /**
     * Chama o primeiro da lista de espera para a data e a área de uma reserva cancelada. Registra a
     * chamada; quem foi chamado faz a reserva normalmente (pelas regras da área).
     *
     * @return array{reservation_id:int, waitlist_id:int, resident_name:string, unit_id:int, called_at:string}
     */
    public function callWaitlist(User $user, int $reservationId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM erp_reservations WHERE id = :id AND deleted_at IS NULL');
            $stmt->execute(['id' => $reservationId]);
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($reservation) || !$this->scope->canAccess($user, (int)$reservation['condominium_id'])) {
                throw new RegistryNotFound();
            }
            if ($reservation['status'] !== 'inactive') {
                throw new DomainException('Só reserva cancelada libera a vez para a lista de espera.');
            }
            if ($this->slotTaken((int)$reservation['common_area_id'], (string)$reservation['reserved_on'])) {
                throw new DomainException('A data já tem outra reserva válida para esta área.');
            }
            $next = $this->pdo->prepare("SELECT * FROM erp_waitlist WHERE common_area_id = :a AND desired_on = :d AND status = 'active'
                AND deleted_at IS NULL AND called_at IS NULL ORDER BY created_at, id LIMIT 1 FOR UPDATE");
            $next->execute(['a' => $reservation['common_area_id'], 'd' => $reservation['reserved_on']]);
            $entry = $next->fetch(PDO::FETCH_ASSOC);
            if (!is_array($entry)) {
                throw new DomainException('Não há ninguém aguardando na lista de espera para esta área e data.');
            }
            $this->pdo->prepare('UPDATE erp_waitlist SET called_at = NOW(), called_by = :u, called_for_reservation_id = :r, updated_by = :u2 WHERE id = :id AND called_at IS NULL')
                ->execute(['u' => (int)$user->id, 'u2' => (int)$user->id, 'r' => $reservationId, 'id' => $entry['id']]);
            // Aviso ao morador fica pendente na outbox (ADR 0054); a fila do send envia depois.
            (new NotificationOutbox($this->pdo))->record('waitlist.called', 'waitlist.called:' . $entry['id'] . ':' . $reservationId,
                (int)$reservation['condominium_id'], 'erp_waitlist', (int)$entry['id'], [
                    'resident_name' => (string)$entry['resident_name'], 'unit_id' => (int)$entry['unit_id'],
                    'common_area_id' => (int)$reservation['common_area_id'], 'date' => (string)$reservation['reserved_on'],
                    'reservation_id' => $reservationId,
                ], (int)$user->id);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'erp_waitlist', (int)$entry['id'], ['called_at' => null], ['called_for_reservation_id' => $reservationId]);
        $stmt = $this->pdo->prepare('SELECT called_at FROM erp_waitlist WHERE id = :id');
        $stmt->execute(['id' => $entry['id']]);
        return ['reservation_id' => $reservationId, 'waitlist_id' => (int)$entry['id'], 'resident_name' => (string)$entry['resident_name'],
            'unit_id' => (int)$entry['unit_id'], 'called_at' => (string)$stmt->fetchColumn()];
    }

    /** Gera a próxima manutenção de uma concluída pela periodicidade (uma vez só; previous_id único). */
    public function nextMaintenance(User $user, int $maintenanceId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM erp_maintenances WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute(['id' => $maintenanceId]);
        $current = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current) || !$this->scope->canAccess($user, (int)$current['condominium_id'])) {
            throw new RegistryNotFound();
        }
        if ($current['status'] !== 'done') {
            throw new DomainException('Conclua a manutenção antes de gerar a próxima.');
        }
        $next = self::nextDate((string)$current['planned_on'], (string)$current['frequency']);
        if ($next === null) {
            throw new InvalidArgumentException('Manutenção única não tem próxima. Mude a periodicidade se for recorrente.');
        }
        try {
            $this->pdo->prepare("INSERT INTO erp_maintenances (condominium_id, service, supplier_id, planned_on, frequency, description, document_id, previous_id, status, created_by, updated_by)
                VALUES (:c, :s, :sup, :p, :f, :d, NULL, :prev, 'active', :u, :u2)")->execute([
                'c' => $current['condominium_id'], 's' => $current['service'], 'sup' => $current['supplier_id'], 'p' => $next,
                'f' => $current['frequency'], 'd' => $current['description'], 'prev' => $maintenanceId, 'u' => (int)$user->id, 'u2' => (int)$user->id,
            ]);
        } catch (PDOException $error) {
            if (($error->errorInfo[1] ?? null) === 1062) {
                throw new DomainException('A próxima manutenção desta já foi gerada.');
            }
            throw $error;
        }
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'erp_maintenances', $id, null, ['previous_id' => $maintenanceId, 'planned_on' => $next, 'frequency' => $current['frequency']]);
        return ['id' => $id, 'previous_id' => $maintenanceId, 'planned_on' => $next, 'service' => (string)$current['service'], 'status' => 'active'];
    }

    /** @return list<array{reservation_id:int, waiting:int}> reservas canceladas futuras com gente aguardando e sem nova reserva válida */
    private function waitlistCandidates(string $in, string $today): array
    {
        $stmt = $this->pdo->prepare("SELECT r.id, COUNT(w.id) AS waiting FROM erp_reservations r
            JOIN erp_waitlist w ON w.common_area_id = r.common_area_id AND w.desired_on = r.reserved_on AND w.status = 'active' AND w.deleted_at IS NULL AND w.called_at IS NULL
            WHERE r.condominium_id IN ({$in}) AND r.deleted_at IS NULL AND r.status = 'inactive' AND r.reserved_on >= :t
              AND NOT EXISTS (SELECT 1 FROM erp_reservations o WHERE o.common_area_id = r.common_area_id AND o.reserved_on = r.reserved_on
                  AND o.deleted_at IS NULL AND o.status IN ('active','confirmed'))
            GROUP BY r.id");
        $stmt->execute(['t' => $today]);
        return array_map(static fn (array $r): array => ['reservation_id' => (int)$r['id'], 'waiting' => (int)$r['waiting']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function slotTaken(int $areaId, string $date): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM erp_reservations WHERE common_area_id = :a AND reserved_on = :d AND deleted_at IS NULL AND status IN ('active','confirmed') LIMIT 1");
        $stmt->execute(['a' => $areaId, 'd' => $date]);
        return (bool)$stmt->fetchColumn();
    }
}
