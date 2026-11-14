<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Avisos pendentes parados há muito tempo cancelados em lote (ADR 0281). A saúde da fila (ADR 0280) aponta em
 * oldest_pending_ids os avisos que esperam há mais tempo. Um aviso pendente há semanas (o envio parou, o modelo mudou, o
 * condomínio trocou de rotina) sai de repente quando a fila volta, com informação velha. Cancelar hoje é um a um, e a fila
 * não tem ação de cancelar: o reenvio (NotificationDispatcher::retry) volta um cancelado à fila, se for preciso.
 *
 * O cancelamento é o mesmo que o cancelamento de comunicado já faz na fila (Announcements::cancel): status cancelled,
 * last_error com o motivo do sistema e processed_at agora, só para quem ainda está pending. Nada é enviado nem apagado; o
 * conteúdo do evento fica como está. Só o pending entra: o processing pode estar com o worker naquele instante.
 *
 * - Entram: os avisos pendentes do condomínio parados há mais de days dias (padrão DEFAULT_DAYS, de 1 a MAX_DAYS), pelo
 *   mais recente entre a criação e o available_at (um aviso agendado para depois não é parado), ou só os event_ids
 *   informados. Regra pura problem(): não encontrado no condomínio, não pendente ou mais novo que o limite.
 * - Tudo ou nada, numa transação com os avisos pendentes do condomínio travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _event). dry_run passa por tudo e desfaz.
 * - Auditoria: um registro cancel_stale por aviso (situação anterior, dias, motivo e tamanho do lote), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem ids, tipo e contagens: nenhum
 * destinatário, e-mail, conteúdo do evento nem mensagem de erro. Até MAX_EVENTS por vez; motivo obrigatório.
 */
final class NotificationStaleCancellation
{
    public const MAX_EVENTS = 500;
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 365;
    /** Mensagem do sistema gravada em last_error, como no cancelamento de comunicado. */
    public const SYSTEM_NOTE = 'Cancelado por estar parado na fila.';

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Limite (regra pura): parado é quem tem o mais recente entre criação e available_at até esta data. */
    public static function cutoff(\DateTimeImmutable $now, int $days): \DateTimeImmutable
    {
        return $now->modify(sprintf('-%d days', $days));
    }

    /** Parado (regra pura): o mais recente entre a criação e o available_at não passa do limite. */
    public static function stale(string $createdAt, ?string $availableAt, \DateTimeImmutable $cutoff): bool
    {
        $last = max(new \DateTimeImmutable($createdAt), new \DateTimeImmutable($availableAt ?? $createdAt));
        return $last <= $cutoff;
    }

    /** Motivo da recusa (regra pura); null quando o aviso pode ser cancelado. */
    public static function problem(?array $row, \DateTimeImmutable $cutoff, int $days): ?string
    {
        return match (true) {
            $row === null => 'Aviso não encontrado neste condomínio.',
            $row['status'] !== 'pending' => 'O aviso não está pendente.',
            !self::stale($row['created_at'], $row['available_at'], $cutoff) => sprintf('O aviso é mais novo que o limite de %d dias.', $days),
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function cancel(User $user, array $input, ?\DateTimeImmutable $now = null): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $days = self::DEFAULT_DAYS;
        if (array_key_exists('days', $input) && $input['days'] !== null && $input['days'] !== '') {
            $days = $int($input['days']);
            if ($days === false || $days > self::MAX_DAYS) {
                throw new InvalidArgumentException(sprintf('Dias parado: de 1 a %d.', self::MAX_DAYS));
            }
        }
        $ids = null;
        if (array_key_exists('event_ids', $input) && $input['event_ids'] !== null) {
            if (!is_array($input['event_ids']) || $input['event_ids'] === []) {
                throw new InvalidArgumentException('Avisos: informe a lista de ids ou deixe vazio para todos os pendentes parados.');
            }
            $ids = [];
            foreach ($input['event_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Avisos: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_EVENTS) {
                throw new InvalidArgumentException(sprintf('Até %d avisos por vez.', self::MAX_EVENTS));
            }
            $ids = array_values($ids);
            sort($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar
        // O relógio do banco: created_at e available_at foram gravados por ele.
        $now ??= new \DateTimeImmutable((string)$this->pdo->query('SELECT NOW()')->fetchColumn());
        $cutoff = self::cutoff($now, (int)$days);

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'reason' => $reason, 'days' => (int)$days,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'), 'events' => []];
        $this->pdo->beginTransaction();
        try {
            $sql = 'SELECT id, event_type, status, created_at, available_at FROM erp_notification_outbox WHERE condominium_id = ?';
            $params = [(int)$condominium];
            if ($ids === null) {
                $sql .= " AND status = 'pending'";
            } else {
                $sql .= ' AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
                $params = array_merge($params, $ids);
            }
            $stmt = $this->pdo->prepare($sql . ' ORDER BY id FOR UPDATE');
            $stmt->execute($params);
            $byId = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byId[(int)$r['id']] = ['id' => (int)$r['id'], 'event_type' => (string)$r['event_type'], 'status' => (string)$r['status'],
                    'created_at' => (string)$r['created_at'], 'available_at' => $r['available_at'] === null ? null : (string)$r['available_at']];
            }
            $rows = [];
            if ($ids === null) {
                foreach ($byId as $row) {
                    if (self::stale($row['created_at'], $row['available_at'], $cutoff)) {
                        $rows[] = $row;
                    }
                }
                if (count($rows) > self::MAX_EVENTS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d avisos parados: cancele em partes (escolha os ids).', self::MAX_EVENTS));
                }
            } else {
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, $cutoff, (int)$days);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_event' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'cancelled', last_error = ?, processed_at = NOW()
                WHERE id = ? AND condominium_id = ? AND status = 'pending'");
            foreach ($rows as $row) {
                $update->execute([self::SYSTEM_NOTE, $row['id'], (int)$condominium]);
                if ($update->rowCount() !== 1) {
                    throw new DomainException('Um aviso mudou de situação enquanto o cancelamento era pedido.');
                }
                $result['events'][] = ['id' => $row['id'], 'event_type' => $row['event_type']];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['events'] as $e) {
                Audit::record('cancel_stale', 'erp_notification_outbox', $e['id'], ['status' => 'pending'], ['status' => 'cancelled', 'days' => (int)$days,
                    'reason' => $reason, 'batch' => count($result['events'])]);
            }
        }
        $byType = [];
        foreach ($result['events'] as $e) {
            $byType[$e['event_type']] = ($byType[$e['event_type']] ?? 0) + 1;
        }
        ksort($byType);
        $result['by_type'] = array_map(static fn (string $type, int $n): array => ['event_type' => $type, 'events' => $n], array_keys($byType), array_values($byType));
        $result['count'] = count($result['events']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
