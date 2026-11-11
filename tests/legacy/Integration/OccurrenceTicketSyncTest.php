<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\OccurrenceTicketSync;
use Source\Services\Erp\Occurrences;
use Source\Services\Erp\Registry\RegistryService;

/** ADR 0213: a ocorrência com o chamado fechado no Help Desk fica resolvida, pelo formulário, tudo ou nada; nada muda no Help Desk. */
final class OccurrenceTicketSyncTest extends TestCase
{
    private int $condominium;
    private int $unit;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
    }

    public function testClosedTicketSettlesTheOccurrence(): void
    {
        $closed = $this->occurrenceWithTicket('Vazamento na garagem do Fulano', 'closed');
        $resolved = $this->occurrenceWithTicket('Portão travando', 'resolved');
        $working = $this->occurrenceWithTicket('Interfone mudo', 'in_progress');
        $plain = $this->registry->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'subject' => 'Barulho',
            'priority' => 'baixa', 'occurred_on' => '2026-11-09', 'description' => 'Sem chamado.'])['id'];

        $preview = (new OccurrenceTicketSync($this->pdo))->sync($this->admin, ['condominium_id' => $this->condominium, 'dry_run' => true]);
        self::assertSame([true, 0, 3, 1, ['in_progress' => 1, 'resolved' => 1], 0, [$closed]], [$preview['dry_run'], $preview['changed'], $preview['checked'],
            $preview['settled'], $preview['still_open'], $preview['unavailable'], array_column($preview['occurrences'], 'id')]);
        self::assertSame(['running', 'running', 'running', 'active'], $this->statuses([$closed, $resolved, $working, $plain]), 'dry_run não grava.');
        $json = (string)json_encode($preview);
        foreach (['Vazamento', 'Fulano', 'requester', 'subject'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem assunto nem solicitante: ' . $needle);
        }

        $done = (new OccurrenceTicketSync($this->pdo))->sync($this->admin, ['condominium_id' => $this->condominium]);
        self::assertSame([1, 'closed'], [$done['changed'], $done['occurrences'][0]['ticket_status']]);
        self::assertSame(['done', 'running', 'running', 'active'], $this->statuses([$closed, $resolved, $working, $plain]));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_occurrences' AND action = 'ticket_sync'")->fetchColumn());
        self::assertSame(['closed', 'resolved', 'in_progress'], $this->ticketStatuses([$closed, $resolved, $working]), 'Nada muda no Help Desk.');

        $withResolved = (new OccurrenceTicketSync($this->pdo))->sync($this->admin, ['condominium_id' => $this->condominium, 'include_resolved' => true]);
        self::assertSame([[$resolved], ['in_progress' => 1]], [array_column($withResolved['occurrences'], 'id'), $withResolved['still_open']]);
        self::assertSame(['done', 'done', 'running'], $this->statuses([$closed, $resolved, $working]));
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new OccurrenceTicketSync($this->pdo))->sync($limited, ['condominium_id' => $this->condominium]);
            self::fail('Sincronizou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function occurrenceWithTicket(string $subject, string $ticketStatus): int
    {
        $id = $this->registry->save($this->admin, 'occurrences', ['condominium_id' => $this->condominium, 'unit_id' => $this->unit, 'subject' => $subject,
            'priority' => 'media', 'occurred_on' => '2026-11-09', 'description' => 'Relato.'])['id'];
        $ticket = (new Occurrences($this->pdo))->openTicket($this->admin, $id);
        $this->pdo->prepare('UPDATE studio_support_tickets SET status = ? WHERE id = ?')->execute([$ticketStatus, $ticket['ticket_id']]);   // o Help Desk mudou o chamado
        return $id;
    }

    /** @return list<string> */
    private function statuses(array $ids): array
    {
        return array_map(fn (int $id): string => (string)$this->pdo->query("SELECT status FROM erp_occurrences WHERE id = {$id}")->fetchColumn(), $ids);
    }

    /** @return list<string> */
    private function ticketStatuses(array $occurrences): array
    {
        return array_map(fn (int $id): string => (string)$this->pdo->query("SELECT t.status FROM studio_support_tickets t JOIN erp_occurrences o ON o.ticket_id = t.id
            WHERE o.id = {$id}")->fetchColumn(), $occurrences);
    }
}
