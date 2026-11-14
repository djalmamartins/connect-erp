<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DomainException;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Support\TicketService;
use Source\Support\Audit;

/**
 * Ocorrência = chamado do Help Desk (ADR 0034, 0051 e 0305).
 *
 * A ocorrência é cadastro do motor (AP-112), mas toda ocorrência é também um chamado: openForNew() roda dentro
 * da gravação do cadastro (RegistryService) e abre o chamado pelo TicketService, na mesma transação, com
 * condomínio, unidade, assunto, descrição e prioridade da ocorrência (origem erp, ou residents quando vem do
 * aplicativo do morador). O atendimento acontece num lugar só, o chamado; a situação da ocorrência segue a do
 * chamado (followTickets, chamado pelo Help Desk e pela resposta do solicitante). openTicket() fica para a
 * ocorrência que ainda estiver sem chamado. Uma ocorrência abre no máximo um chamado (chave única em
 * erp_occurrences.ticket_id e trava da linha). Quem registra é o solicitante.
 */
final class Occurrences
{
    public const PRIORITY_MAP = ['baixa' => 'low', 'media' => 'medium', 'alta' => 'high'];

    /** Situação da ocorrência pela situação do chamado (ADR 0305). */
    public const STATUS_FROM_TICKET = [
        'open' => 'active', 'in_progress' => 'running', 'waiting_customer' => 'running', 'resolved' => 'done', 'closed' => 'done',
    ];

    private PDO $pdo;
    private CondominiumScope $scope;
    private TicketService $tickets;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null, ?TicketService $tickets = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
        $this->tickets = $tickets ?? new TicketService($this->pdo, $this->scope);
    }

    /** Dados do chamado a partir da ocorrência (regra pura). */
    public static function ticketInput(array $occurrence): array
    {
        $message = trim((string)$occurrence['description']);
        if (mb_strlen($message) < 10) {
            $message = 'Ocorrência registrada no ERP: ' . $message;
        }
        $subject = trim((string)$occurrence['subject']);
        return [
            'subject' => mb_substr(mb_strlen($subject) >= 4 ? $subject : 'Ocorrência: ' . $subject, 0, 180),
            'message' => $message . "\n\n(Ocorrência #" . (int)$occurrence['id'] . ' de ' . (string)$occurrence['occurred_on'] . ')',
            'priority' => self::PRIORITY_MAP[$occurrence['priority']] ?? 'medium',
            'area' => 'general', 'category' => 'Ocorrência',
            'condominium_id' => (int)$occurrence['condominium_id'],
            'unit_id' => $occurrence['unit_id'] !== null ? (int)$occurrence['unit_id'] : null,
        ];
    }

    /** Situação que a ocorrência deve ter com o chamado nesta situação (regra pura). */
    public static function statusForTicket(string $ticketStatus): string
    {
        return self::STATUS_FROM_TICKET[$ticketStatus] ?? 'running';
    }

    /**
     * Chamado da ocorrência que acabou de ser gravada (ADR 0305). Roda dentro da transação do cadastro: se o
     * chamado não abrir, a ocorrência também não fica. Do aplicativo do morador ($fromResident), a origem é
     * residents e o morador é o solicitante (sem a regra de escopo do ERP, que o vínculo já resolveu).
     *
     * @param array<string, mixed> $values colunas gravadas (condominium_id, unit_id, subject, priority, occurred_on, description)
     * @return array{id:int, protocol:string}
     * @throws \Source\Services\Support\TicketError
     */
    public function openForNew(User $user, int $occurrenceId, array $values, bool $fromResident): array
    {
        $input = self::ticketInput(['id' => $occurrenceId] + $values + ['unit_id' => null, 'occurred_on' => date('Y-m-d')]);
        if ($fromResident) {
            $input['requester_id'] = (int)$user->id;
        }
        $ticket = $this->tickets->open($input, $fromResident ? 'residents' : 'erp', $fromResident ? null : $user,
            static function (PDO $pdo, int $ticketId) use ($occurrenceId): void {
                $pdo->prepare('UPDATE erp_occurrences SET ticket_id = :t WHERE id = :id AND ticket_id IS NULL')->execute(['t' => $ticketId, 'id' => $occurrenceId]);
            });
        return ['id' => (int)$ticket['id'], 'protocol' => (string)$ticket['protocol']];
    }

    /**
     * A ocorrência acompanha o chamado (ADR 0305): depois que o Help Desk (ou o solicitante) muda a situação dos
     * chamados, as ocorrências ligadas a eles passam para a situação correspondente (STATUS_FROM_TICKET). Uma
     * gravação de auditoria por ocorrência alterada. Chamado sem ocorrência: nada acontece.
     *
     * @param list<int> $ticketIds
     * @return int ocorrências alteradas
     */
    public function followTickets(array $ticketIds, ?int $userId = null): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT o.id, o.status, t.status AS ticket_status, t.protocol FROM erp_occurrences o
            JOIN studio_support_tickets t ON t.id = o.ticket_id WHERE o.ticket_id IN ({$in}) AND o.deleted_at IS NULL");
        $stmt->execute($ids);
        $changed = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $target = self::statusForTicket((string)$row['ticket_status']);
            if ($target === (string)$row['status']) {
                continue;
            }
            $update = $this->pdo->prepare('UPDATE erp_occurrences SET status = :s, updated_by = COALESCE(:u, updated_by) WHERE id = :id AND status <> :s2');
            $update->execute(['s' => $target, 's2' => $target, 'u' => $userId, 'id' => (int)$row['id']]);
            if ($update->rowCount() > 0) {
                $changed++;
                Audit::record('ticket_follow', 'erp_occurrences', (int)$row['id'], ['status' => (string)$row['status']],
                    ['status' => $target, 'protocol' => (string)$row['protocol'], 'ticket_status' => (string)$row['ticket_status']]);
            }
        }
        return $changed;
    }

    /**
     * A situação escolhida no formulário combina com o chamado da ocorrência? (ADR 0305) null = pode gravar; senão,
     * a mensagem para o campo. Sem chamado, ou sem mudança de situação, sempre pode.
     */
    public function statusLockMessage(array $before, array $values): ?string
    {
        if (($before['ticket_id'] ?? null) === null || !array_key_exists('status', $values) || (string)$values['status'] === (string)($before['status'] ?? '')) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT status, protocol FROM studio_support_tickets WHERE id = ?');
        $stmt->execute([(int)$before['ticket_id']]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket) || self::statusForTicket((string)$ticket['status']) === (string)$values['status']) {
            return null;
        }
        return sprintf('A situação desta ocorrência segue o chamado %s. Atualize o chamado, e a ocorrência acompanha.', (string)$ticket['protocol']);
    }

    /** @return array{occurrence_id:int, ticket_id:int, protocol:string} */
    public function openTicket(User $user, int $occurrenceId): array
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM erp_occurrences WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $stmt->execute(['id' => $occurrenceId]);
            $occurrence = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($occurrence) || !$this->scope->canAccess($user, (int)$occurrence['condominium_id'])) {
                throw new RegistryNotFound();
            }
            if ($occurrence['ticket_id'] !== null) {
                throw new DomainException('Esta ocorrência já tem chamado aberto no Help Desk.');
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        // O vínculo é gravado dentro da transação do chamado (ADR 0101): com a ocorrência travada, um segundo
        // pedido ao mesmo tempo encontra o ticket_id já preenchido e desfaz o próprio chamado, em vez de
        // deixar no Help Desk um chamado sem ocorrência.
        $ticket = $this->tickets->open(self::ticketInput($occurrence), 'erp', $user, static function (PDO $pdo, int $ticketId) use ($occurrenceId, $user): void {
            $lock = $pdo->prepare('SELECT ticket_id FROM erp_occurrences WHERE id = :id AND deleted_at IS NULL FOR UPDATE');
            $lock->execute(['id' => $occurrenceId]);
            $current = $lock->fetch(PDO::FETCH_ASSOC);
            if (!is_array($current) || $current['ticket_id'] !== null) {
                throw new DomainException('Esta ocorrência já tem chamado aberto no Help Desk.');
            }
            $pdo->prepare('UPDATE erp_occurrences SET ticket_id = :t, status = CASE WHEN status = \'active\' THEN \'running\' ELSE status END, updated_by = :u
                WHERE id = :id AND ticket_id IS NULL')->execute(['t' => $ticketId, 'u' => (int)$user->id, 'id' => $occurrenceId]);
        });
        Audit::record('update', 'erp_occurrences', $occurrenceId, ['ticket_id' => null], ['ticket_id' => $ticket['id'], 'protocol' => $ticket['protocol']]);
        return ['occurrence_id' => $occurrenceId, 'ticket_id' => (int)$ticket['id'], 'protocol' => (string)$ticket['protocol']];
    }
}
