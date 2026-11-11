<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\TicketServiceTimes;
use Source\Services\Support\TicketService;

/** ADR 0226: tempo de atendimento dos chamados abertos pelo TicketService; somente leitura e sem dado do chamado. */
final class TicketServiceTimesTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testServiceTimesByAreaAndPriority(): void
    {
        $quick = $this->ticket('technical', 'high', 'resolved', '2026-10-01 08:00:00', '2026-10-03 08:00:00', '2026-10-02 08:00:00');
        $slow = $this->ticket('technical', 'high', 'closed', '2026-10-05 08:00:00', '2026-10-07 08:00:00', '2026-10-08 08:00:00');
        $stuck = $this->ticket('technical', 'urgent', 'open', '2026-10-30 10:00:00', '2026-10-31 10:00:00', null);
        $this->ticket('financial', 'low', 'resolved', '2026-09-20 08:00:00', '2026-09-25 08:00:00', '2026-09-21 08:00:00');   // fora do período
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new TicketServiceTimes($this->pdo))->build($this->admin, $this->condominium, '2026-10-01', '2026-10-31');
        self::assertSame([['technical', 'urgent', 1, null, null, 1], ['technical', 'high', 2, 48.0, 50.0, 0]],
            array_map(static fn (array $r): array => [$r['area'], $r['priority'], $r['created'], $r['avg_resolution_hours'], $r['on_time_pct'], $r['overdue_open']],
                $report['rows']));
        self::assertSame([3, 2, 1, 1, 1, 48.0, 50.0], [$report['totals']['created'], $report['totals']['timed'], $report['totals']['on_time'], $report['totals']['late'],
            $report['totals']['overdue_open'], $report['totals']['avg_resolution_hours'], $report['totals']['on_time_pct']]);
        $json = (string)json_encode($report);
        foreach (['Fulano', 'Vazamento', 'protocol', 'requester'] as $needle) {
            self::assertStringNotContainsString($needle, $json, 'Sem dado do chamado: ' . $needle);
        }
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        self::assertSame(['resolved', 'closed', 'open'], array_map(fn (int $id): string => (string)$this->pdo->query("SELECT status FROM studio_support_tickets
            WHERE id = {$id}")->fetchColumn(), [$quick, $slow, $stuck]), 'Os chamados não mudam.');
    }

    public function testScope(): void
    {
        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new TicketServiceTimes($this->pdo))->build($limited, $this->condominium);
            self::fail('Consultou condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function ticket(string $area, string $priority, string $status, string $created, string $due, ?string $resolved): int
    {
        $ticket = (new TicketService($this->pdo, new CondominiumScope($this->pdo)))->open(['subject' => 'Vazamento do Fulano', 'message' => 'Água vazando na garagem.',
            'area' => $area, 'priority' => $priority, 'condominium_id' => $this->condominium], 'erp', $this->admin);
        $this->pdo->prepare('UPDATE studio_support_tickets SET status = ?, created_at = ?, due_at = ?, resolved_at = ? WHERE id = ?')
            ->execute([$status, $created, $due, $resolved, $ticket['id']]);   // o Help Desk atendeu
        return (int)$ticket['id'];
    }
}
