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
 * Ocorrência → chamado na fila do Help Desk (ADR 0034 e 0051).
 *
 * A ocorrência é cadastro do motor (AP-112). Quando precisa de ação da administradora, openTicket()
 * abre o chamado pelo TicketService (origem erp), com condomínio, unidade, assunto, descrição e
 * prioridade da ocorrência, e grava o ticket_id. Uma ocorrência abre no máximo um chamado (chave
 * única em erp_occurrences.ticket_id e trava da linha). Quem registra é o solicitante.
 */
final class Occurrences
{
    public const PRIORITY_MAP = ['baixa' => 'low', 'media' => 'medium', 'alta' => 'high'];

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
        // O TicketService abre a própria transação; a chave única em ticket_id barra dois chamados.
        $ticket = $this->tickets->open(self::ticketInput($occurrence), 'erp', $user);
        $update = $this->pdo->prepare('UPDATE erp_occurrences SET ticket_id = :t, status = CASE WHEN status = \'active\' THEN \'running\' ELSE status END, updated_by = :u
            WHERE id = :id AND ticket_id IS NULL');
        $update->execute(['t' => $ticket['id'], 'u' => (int)$user->id, 'id' => $occurrenceId]);
        if ($update->rowCount() === 0) {
            throw new DomainException('Esta ocorrência já tem chamado aberto no Help Desk.');
        }
        Audit::record('update', 'erp_occurrences', $occurrenceId, ['ticket_id' => null], ['ticket_id' => $ticket['id'], 'protocol' => $ticket['protocol']]);
        return ['occurrence_id' => $occurrenceId, 'ticket_id' => (int)$ticket['id'], 'protocol' => (string)$ticket['protocol']];
    }
}
