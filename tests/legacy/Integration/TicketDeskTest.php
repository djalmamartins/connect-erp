<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Support\TicketDesk;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;

/** ADR 0306: o atendimento do chamado é o mesmo no Help Desk e no ERP (triagem, cronômetro, nota interna, lote, números da fila). */
final class TicketDeskTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        }
        $this->beta = (int)$this->pdo->lastInsertId();
        $this->alpha = $this->beta - 1;
        $id = $this->createUser(['level' => 5]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->agent = (new User())->findById($id);
    }

    public function testAttendTicketLikeTheHelpDesk(): void
    {
        $registry = new RegistryService($this->pdo);
        $occurrence = $registry->save($this->agent, 'occurrences', ['condominium_id' => $this->alpha, 'subject' => 'Portão travado', 'priority' => 'media',
            'occurred_on' => date('Y-m-d'), 'description' => 'O portão da garagem não abre.'])['id'];
        $ticket = (int)$this->pdo->query("SELECT ticket_id FROM erp_occurrences WHERE id = {$occurrence}")->fetchColumn();
        $desk = new TicketDesk($this->pdo);

        // Nota interna com 90 s de cronômetro: primeira resposta marcada, chamado novo vai para "em atendimento".
        $note = $desk->reply($this->agent, $ticket, 'Verificar com o zelador.', true, 90);
        self::assertSame('in_progress', $note['status']);
        $facts = $desk->facts($ticket);
        self::assertSame([90, 1, 1], [$facts['work_seconds'], $facts['replies'], $facts['internal_notes']]);
        self::assertNotNull($facts['first_response_at']);
        self::assertSame('running', (string)$this->pdo->query("SELECT status FROM erp_occurrences WHERE id = {$occurrence}")->fetchColumn(), 'A ocorrência acompanha.');
        $public = (new TicketService($this->pdo))->conversation((new TicketService($this->pdo))->findFor($this->agent, $ticket));
        self::assertSame([], $public, 'Quem pediu não vê a nota interna.');

        // Triagem: só o que mudou vira evento.
        $result = $desk->update($this->agent, $ticket, ['priority' => 'high', 'assigned_to' => (int)$this->agent->id, 'team' => 'Manutenção', 'tags' => 'portão']);
        self::assertSame(['priority', 'assigned_to', 'team', 'tags'], $result['changed']);
        self::assertSame([], $desk->update($this->agent, $ticket, ['priority' => 'high'])['changed']);
        $labels = array_column($desk->events($ticket), 'label');
        self::assertContains('Responsável', $labels);
        self::assertContains('Aberto por', $labels);

        // Resposta ao cliente resolvendo: resolved_at preenchido, ocorrência resolvida.
        self::assertSame('resolved', $desk->reply($this->agent, $ticket, 'Portão consertado.', false, 30, true)['status']);
        self::assertSame(120, $desk->facts($ticket)['work_seconds']);
        self::assertNotNull($this->pdo->query("SELECT resolved_at FROM studio_support_tickets WHERE id = {$ticket}")->fetchColumn());
        self::assertSame('done', (string)$this->pdo->query("SELECT status FROM erp_occurrences WHERE id = {$occurrence}")->fetchColumn());

        // Lote limitado ao escopo: o chamado do Beta não muda.
        $other = (new TicketService($this->pdo))->open(['subject' => 'Outro condomínio', 'message' => 'Chamado de outro condomínio.', 'condominium_id' => $this->beta,
            'requester_name' => 'Fulano', 'requester_email' => 'f@exemplo.test'], 'site');
        $allowed = array_column((new TicketService($this->pdo))->listFor($this->agent, [], 200), 'id');
        self::assertSame(1, $desk->bulkStatus($this->agent, [$ticket, (int)$other['id']], 'closed', $allowed));
        self::assertSame('open', (string)$this->pdo->query('SELECT status FROM studio_support_tickets WHERE id = ' . (int)$other['id'])->fetchColumn());

        // Números da fila no escopo do ERP e no Help Desk (todos).
        $scoped = $desk->metrics([$this->alpha], (int)$this->agent->id);
        self::assertSame([1, 1, 0], [$scoped['total'], $scoped['by_status']['closed'], $scoped['open_total']]);
        $all = $desk->metrics();
        self::assertSame([2, 1, 1], [$all['total'], $all['open_total'], $all['unassigned']]);

        // Filtros da fila.
        $service = new TicketService($this->pdo);
        self::assertSame([$ticket], array_column($service->listFor($this->agent, ['priority' => 'high', 'assigned' => (string)$this->agent->id]), 'id'));
        self::assertSame([], $service->listFor($this->agent, ['assigned' => 'unassigned']));

        $this->expectException(TicketError::class);
        $desk->reply($this->agent, $ticket, ' ', false);
    }
}
