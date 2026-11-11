<?php

declare(strict_types=1);

namespace Source\Services\Support;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;

/**
 * Reatribuição em lote dos chamados de um responsável para outro (ADR 0227): quando alguém da equipe do Help Desk sai de
 * férias ou muda de carteira, os chamados abertos dele em um condomínio são passados para outra pessoa. No Help Desk a
 * troca é chamado por chamado (Studio, ação "update"); a ação em lote do Help Desk só muda a situação.
 *
 * Reusa o que já existe: as situações em aberto do TicketService (aberto, em atendimento e aguardando cliente), a regra de
 * quem pode ser responsável (nível da equipe, como na lista de responsáveis do Help Desk) e o mesmo histórico do chamado
 * (studio_support_ticket_events com event_type assigned_to, valor antigo e novo), como a edição de um chamado grava.
 * Nada mais muda no chamado: situação, prioridade e prazo ficam como estão. Nenhum aviso é enviado.
 *
 * - Entram: os chamados em aberto do condomínio atribuídos a from_user_id, ou só os ticket_ids informados. Regra pura
 *   problem(): chamado não encontrado no condomínio, concluído (resolvido ou fechado) ou de outro responsável.
 * - Tudo ou nada, numa transação com os chamados travados (FOR UPDATE): um recusado desfaz todos
 *   (RegistryValidationException com _form e _ticket). dry_run passa por tudo e desfaz no fim.
 * - Auditoria: um registro reassign por chamado (de, para e motivo), só quando grava.
 *
 * Escopo (ADR-002): um condomínio do escopo (fora = CondominiumAccessDenied, 404). Saem id, prioridade, situação e prazo:
 * nenhum protocolo, assunto, mensagem, solicitante ou nome de responsável (só os ids). Até MAX_TICKETS por vez.
 */
final class TicketReassignment
{
    public const MAX_TICKETS = 500;
    public const OPEN = ['open', 'in_progress', 'waiting_customer'];
    public const TEAM_LEVEL = 5;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Motivo da recusa (regra pura); null quando o chamado pode ser reatribuído. */
    public static function problem(?array $row, int $fromUserId): ?string
    {
        return match (true) {
            $row === null => 'Chamado não encontrado neste condomínio.',
            !in_array((string)($row['status'] ?? ''), self::OPEN, true) => 'Chamado concluído: não é reatribuído.',
            (int)($row['assigned_to'] ?? 0) !== $fromUserId => 'Chamado de outro responsável.',
            default => null,
        };
    }

    /** @param array<string, mixed> $input */
    public function reassign(User $user, array $input): array
    {
        $int = static fn (mixed $value): int|false => is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
        $condominium = $int($input['condominium_id'] ?? null);
        if ($condominium === false) {
            throw new InvalidArgumentException('Escolha o condomínio.');
        }
        $from = $int($input['from_user_id'] ?? null);
        $to = $int($input['to_user_id'] ?? null);
        if ($from === false || $to === false) {
            throw new InvalidArgumentException('Escolha o responsável atual e o novo responsável.');
        }
        if ($from === $to) {
            throw new InvalidArgumentException('O novo responsável precisa ser outra pessoa.');
        }
        $reason = is_string($input['reason'] ?? null) ? trim($input['reason']) : '';
        if (mb_strlen($reason) < 5 || mb_strlen($reason) > 255) {
            throw new InvalidArgumentException('Informe o motivo (5 a 255 caracteres).');
        }
        $ids = null;
        if (array_key_exists('ticket_ids', $input) && $input['ticket_ids'] !== null) {
            if (!is_array($input['ticket_ids']) || $input['ticket_ids'] === []) {
                throw new InvalidArgumentException('Chamados: informe a lista de ids ou deixe vazio para todos os abertos do responsável.');
            }
            $ids = [];
            foreach ($input['ticket_ids'] as $value) {
                $id = $int($value);
                if ($id === false) {
                    throw new InvalidArgumentException('Chamados: informe a lista de ids.');
                }
                $ids[$id] = $id;
            }
            if (count($ids) > self::MAX_TICKETS) {
                throw new InvalidArgumentException(sprintf('Reatribua até %d chamados por vez.', self::MAX_TICKETS));
            }
            $ids = array_values($ids);
        }
        $dryRun = filter_var($input['dry_run'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $this->scope->assertAccess($user, (int)$condominium);   // 404 antes de consultar

        $stmt = $this->pdo->prepare('SELECT level FROM users WHERE id = ?');
        $stmt->execute([(int)$to]);
        $level = $stmt->fetchColumn();
        if ($level === false || (int)$level < self::TEAM_LEVEL) {
            throw new InvalidArgumentException('O novo responsável precisa ser da equipe do Help Desk.');
        }

        $result = ['condominium_id' => (int)$condominium, 'dry_run' => $dryRun, 'from_user_id' => (int)$from, 'to_user_id' => (int)$to, 'reason' => $reason,
            'tickets' => []];
        $this->pdo->beginTransaction();
        try {
            if ($ids === null) {
                $stmt = $this->pdo->prepare('SELECT id, status, priority, due_at, assigned_to FROM studio_support_tickets WHERE condominium_id = ? AND assigned_to = ?
                    AND status IN (' . implode(',', array_fill(0, count(self::OPEN), '?')) . ') ORDER BY id LIMIT ' . (self::MAX_TICKETS + 1) . ' FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium, (int)$from], self::OPEN));
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if (count($rows) > self::MAX_TICKETS) {
                    throw new InvalidArgumentException(sprintf('Mais de %d chamados abertos: escolha os ids.', self::MAX_TICKETS));
                }
            } else {
                $stmt = $this->pdo->prepare('SELECT id, status, priority, due_at, assigned_to FROM studio_support_tickets WHERE condominium_id = ? AND id IN ('
                    . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE');
                $stmt->execute(array_merge([(int)$condominium], $ids));
                $byId = [];
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                    $byId[(int)$r['id']] = $r;
                }
                $rows = [];
                foreach ($ids as $id) {
                    $problem = self::problem($byId[$id] ?? null, (int)$from);
                    if ($problem !== null) {
                        throw new RegistryValidationException(['_form' => $problem, '_ticket' => (string)$id]);
                    }
                    $rows[] = $byId[$id];
                }
            }
            $update = $this->pdo->prepare('UPDATE studio_support_tickets SET assigned_to = ? WHERE id = ? AND assigned_to = ?');
            $event = $this->pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, old_value, new_value) VALUES (?, ?, ?, ?, ?)');
            foreach ($rows as $row) {
                $update->execute([(int)$to, (int)$row['id'], (int)$from]);
                if ($update->rowCount() !== 1) {
                    throw new RegistryValidationException(['_form' => 'O chamado mudou durante a reatribuição.', '_ticket' => (string)$row['id']]);
                }
                $event->execute([(int)$row['id'], (int)$user->id, 'assigned_to', (string)$from, (string)$to]);
                $result['tickets'][] = ['id' => (int)$row['id'], 'priority' => (string)$row['priority'], 'status' => (string)$row['status'],
                    'due_at' => $row['due_at'] !== null ? (string)$row['due_at'] : null];
            }
            $dryRun ? $this->pdo->rollBack() : $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        if (!$dryRun) {
            foreach ($result['tickets'] as $t) {
                Audit::record('reassign', 'studio_support_tickets', $t['id'], ['assigned_to' => (int)$from],
                    ['assigned_to' => (int)$to, 'reason' => $reason, 'batch' => count($result['tickets'])]);
            }
        }
        $result['count'] = count($result['tickets']);
        $result['changed'] = $dryRun ? 0 : $result['count'];
        return $result;
    }
}
