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
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Baixa em lote das reservas que passaram sem aprovação (ADR 0221). A reserva de área comum com aprovação nasce
 * "Aguardando aprovação" (status active) e aparece em Agendamentos Pendentes, no painel operacional e no resumo do
 * síndico até alguém aprovar ou cancelar. Quando a data passa sem decisão, ela fica pendente para sempre e polui essas
 * listas; hoje cada uma é cancelada à mão no formulário.
 *
 * Reusa o que já existe: cada reserva é regravada pelo RegistryService::save do cadastro "reservations" só com a
 * situação "Cancelada" (valem as regras e a auditoria update do formulário). O aviso da outbox (ADR 0056) é só da
 * aprovação (aguardando → confirmada), então cancelar não envia nada; reserva confirmada não é tocada (a cobrança da taxa
 * é da ADR 0212).
 *
 * - Entram: reservas aguardando aprovação do condomínio com data antes de before (padrão hoje; nunca depois de hoje), ou
 *   só as reservation_ids informadas. Regra pura problem(): reserva não encontrada no condomínio, que não está
 *   aguardando aprovação, ou cuja data ainda não passou.
 * - Tudo ou nada, numa transação com as reservas travadas (FOR UPDATE): uma recusada desfaz todas
 *   (RegistryValidationException com _form ou os erros do formulário, e _reservation). dry_run passa por tudo e desfaz.
 * - Auditoria: a update do formulário e um registro expire por reserva (data, área e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, área, unidade, data e taxa:
 * nenhum nome de morador. Até MAX_RESERVATIONS por vez; motivo obrigatório.
 */
final class ReservationExpiry
{
    public const MAX_RESERVATIONS = 500;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa antes do formulário (regra pura); null quando a reserva pode ser baixada. */
    public static function problem(?array $row, string $before): ?string
    {
        return match (true) {
            $row === null => 'Reserva não encontrada neste condomínio.',
            ($row['status'] ?? '') !== 'active' => 'A reserva não está aguardando aprovação.',
            substr((string)($row['reserved_on'] ?? ''), 0, 10) >= $before => 'A data da reserva ainda não passou.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function expire(User $user, array $input): array
    {
        $condominium = filter_var($input['condominium_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($condominium === false || $condominium === null) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $today = date('Y-m-d');
        $before = $input['before'] ?? null;
        $before = $before === null || $before === '' ? $today : $before;
        $date = is_string($before) ? DateTimeImmutable::createFromFormat('!Y-m-d', $before) : false;
        if ($date === false || $date->format('Y-m-d') !== $before || $before > $today) {
            throw new InvalidArgumentException('Data de corte: AAAA-MM-DD, até hoje.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('reservation_ids', $input) && $input['reservation_ids'] !== null) {
            if (!is_array($input['reservation_ids']) || $input['reservation_ids'] === []) {
                throw new InvalidArgumentException('Reservas: informe a lista de ids ou deixe vazio para todas as pendentes vencidas.');
            }
            $ids = [];
            foreach ($input['reservation_ids'] as $value) {
                $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
                if ($id === false) {
                    throw new InvalidArgumentException('Reservas: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_RESERVATIONS) {
                throw new InvalidArgumentException(sprintf('Até %d reservas por vez.', self::MAX_RESERVATIONS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $fields = array_keys(RegistryService::definition('reservations')['fields']);
        $registry = new RegistryService($this->pdo, $this->scope);
        $columns = 'id, status, ' . implode(', ', array_map(static fn (string $f): string => "`{$f}`", $fields));
        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'before' => $before, 'reason' => $reason, 'reservations' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL AND status = 'active'
                    AND reserved_on < ? ORDER BY reserved_on, id LIMIT " . (self::MAX_RESERVATIONS + 1) . ' FOR UPDATE');
                $stmt->execute([(int)$condominium, $before]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_RESERVATIONS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d reservas pendentes vencidas: baixe em partes (data de corte menor).', self::MAX_RESERVATIONS));
                }
            } else {
                $stmt = $this->pdo->prepare("SELECT {$columns} FROM erp_reservations WHERE condominium_id = ? AND deleted_at IS NULL AND id IN ("
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $before);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_reservation' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            foreach ($rows as $row) {
                $data = [];
                foreach ($fields as $field) {
                    $value = $row[$field];
                    $data[$field] = match (true) {
                        $value === null => null,
                        $field === 'fee_cents' => number_format(((int)$value) / 100, 2, ',', ''),
                        in_array($field, ['starts_time', 'ends_time'], true) => substr((string)$value, 0, 5),
                        $field === 'reserved_on' => substr((string)$value, 0, 10),
                        default => $value,
                    };
                }
                try {
                    $registry->save($user, 'reservations', array_merge($data, ['status' => 'inactive']), (int)$row['id']);
                } catch (RegistryValidationException $error) {
                    throw new RegistryValidationException($error->errors + ['_reservation' => (string)$row['id']]);
                }
                $result['reservations'][] = ['id' => (int)$row['id'], 'common_area_id' => (int)$row['common_area_id'],
                    'unit_id' => $row['unit_id'] !== null ? (int)$row['unit_id'] : null, 'reserved_on' => substr((string)$row['reserved_on'], 0, 10),
                    'fee_cents' => $row['fee_cents'] !== null ? (int)$row['fee_cents'] : null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['reservations'] as $r) {
                Audit::record('expire', 'erp_reservations', $r['id'], ['status' => 'active'],
                    ['status' => 'inactive', 'reserved_on' => $r['reserved_on'], 'common_area_id' => $r['common_area_id'], 'reason' => $reason,
                        'batch' => count($result['reservations'])]);
            }
        }
        $result['count'] = count($result['reservations']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
