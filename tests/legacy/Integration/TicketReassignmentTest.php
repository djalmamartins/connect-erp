<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Support\TicketReassignment;
use Source\Services\Support\TicketService;

/** ADR 0227: reatribuição em lote dos chamados abertos pelo TicketService, com histórico, auditoria e nenhum aviso. */
final class TicketReassignmentTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private int $away;
    private int $cover;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 5]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->away = $this->createUser(['level' => 5, 'first_name' => 'Ana']);
        $this->cover = $this->createUser(['level' => 5, 'first_name' => 'Bruno']);
    }

    public function testReassignsOpenTicketsWithHistory(): void
    {
        $first = $this->ticket('high', 'open', $this->away);
        $second = $this->ticket('low', 'waiting_customer', $this->away);
        $done = $this->ticket('low', 'resolved', $this->away);
        $other = $this->ticket('medium', 'open', $this->cover);
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE title <> \'Alteração no sistema\'')->fetchColumn();
        $input = ['condominium_id' => $this->condominium, 'from_user_id' => $this->away, 'to_user_id' => $this->cover, 'reason' => 'Férias programadas'];

        $preview = (new TicketReassignment($this->pdo))->reassign($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, [$first, $second]], [$preview['dry_run'], array_column($preview['tickets'], 'id')]);
        self::assertSame($this->away, $this->assignee($first), 'dry_run não grava.');

        $done2 = (new TicketReassignment($this->pdo))->reassign($this->admin, $input);
        self::assertSame(2, $done2['changed']);
        self::assertSame([$this->cover, $this->cover, $this->away, $this->cover], [$this->assignee($first), $this->assignee($second), $this->assignee($done),
            $this->assignee($other)]);
        self::assertSame(['open', 'high'], array_values($this->pdo->query("SELECT status, priority FROM studio_support_tickets WHERE id = {$first}")->fetch(\PDO::FETCH_ASSOC)),
            'Situação e prioridade intactas.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_events WHERE event_type = 'assigned_to' AND old_value = '{$this->away}'
            AND new_value = '{$this->cover}'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'studio_support_tickets' AND action = 'reassign'")->fetchColumn());
        self::assertSame([$mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE title <> \'Alteração no sistema\'')->fetchColumn()], 'Nenhum aviso.');
        self::assertStringNotContainsString('Ana', (string)json_encode($done2));
    }

    public function testChosenIdsAllOrNothingAndScope(): void
    {
        $open = $this->ticket('high', 'open', $this->away);
        $closed = $this->ticket('low', 'closed', $this->away);
        try {
            (new TicketReassignment($this->pdo))->reassign($this->admin, ['condominium_id' => $this->condominium, 'from_user_id' => $this->away,
                'to_user_id' => $this->cover, 'reason' => 'Férias programadas', 'ticket_ids' => [$open, $closed]]);
            self::fail('Chamado fechado entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$closed, $exception->errors['_ticket']);
        }
        self::assertSame($this->away, $this->assignee($open), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 5]));
        try {
            (new TicketReassignment($this->pdo))->reassign($limited, ['condominium_id' => $this->condominium, 'from_user_id' => $this->away, 'to_user_id' => $this->cover,
                'reason' => 'Férias programadas']);
            self::fail('Reatribuiu fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function ticket(string $priority, string $status, int $assignee): int
    {
        $ticket = (new TicketService($this->pdo, new CondominiumScope($this->pdo)))->open(['subject' => 'Vazamento do Fulano', 'message' => 'Água vazando na garagem.',
            'priority' => $priority, 'condominium_id' => $this->condominium], 'erp', $this->admin);
        $this->pdo->prepare('UPDATE studio_support_tickets SET status = ?, assigned_to = ? WHERE id = ?')->execute([$status, $assignee, $ticket['id']]);
        return (int)$ticket['id'];
    }

    private function assignee(int $ticket): int
    {
        return (int)$this->pdo->query("SELECT assigned_to FROM studio_support_tickets WHERE id = {$ticket}")->fetchColumn();
    }
}
