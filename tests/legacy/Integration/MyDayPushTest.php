<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Services\Erp\MyDayPush;

/** Push do app Meu Dia (ADR 0659) no MariaDB de teste: tarefa marcada por outra pessoa e resumo das 7h, uma vez só. */
final class MyDayPushTest extends TestCase
{
    /** @var list<array{0:int,1:string,2:string,3:array<string,mixed>}> */
    private array $sent = [];

    private function push(array $recipients = [], ?callable $day = null): MyDayPush
    {
        return new MyDayPush(
            $this->pdo,
            function (int $userId, string $title, string $body, array $data): void {
                $this->sent[] = [$userId, $title, $body, $data];
            },
            static fn (): array => $recipients,
            $day ?? static fn (int $userId, DateTimeImmutable $today): array => ['events' => [], 'summary' => ['to_schedule' => 0, 'overdue' => 0], 'overdue' => []],
        );
    }

    private function event(int $creator, ?int $assignee, string $title, string $startsAt, string $status = 'scheduled', string $createdAt = '2026-11-26 21:50:00'): int
    {
        $this->pdo->prepare("INSERT INTO studio_calendar_events (title, starts_at, type, status, assigned_to, created_by, created_at) VALUES (?, ?, 'task', ?, ?, ?, ?)")
            ->execute([$title, $startsAt, $status, $assignee, $creator, $createdAt]);
        return (int)$this->pdo->lastInsertId();
    }

    public function testTaskMarkedForSomeoneElseNotifiesOnce(): void
    {
        $boss = $this->createUser(['first_name' => 'Djalma', 'last_name' => 'Martins', 'level' => 5]);
        $staff = $this->createUser(['level' => 5]);
        $now = new DateTimeImmutable('2026-11-26 22:00:00');
        $id = $this->event($boss, $staff, 'Ligar para o síndico', '2026-11-27 09:30:00');
        $this->event($boss, $boss, 'Para mim mesmo', '2026-11-27 10:00:00');
        $this->event($boss, $staff, 'Cancelada', '2026-11-27 11:00:00', 'cancelled');
        $this->event($boss, $staff, 'Antiga', '2026-11-27 11:00:00', 'scheduled', '2026-11-20 10:00:00');

        self::assertSame(1, $this->push()->assigned($now));
        self::assertSame($staff, $this->sent[0][0]);
        self::assertSame('Djalma Martins marcou uma tarefa para você', $this->sent[0][1]);
        self::assertSame('Ligar para o síndico · 27/11 às 09:30', $this->sent[0][2]);
        self::assertSame(['app' => 'meu-dia', 'kind' => 'assigned', 'id' => $id, 'date' => '2026-11-27'], $this->sent[0][3]);

        self::assertSame(0, $this->push()->assigned($now), 'a segunda rodada do cron não repete');
    }

    public function testMorningSummaryOnlyAfterSevenOncePerDayAndNotOnEmptyDays(): void
    {
        $late = $this->createUser(['level' => 5]);
        $empty = $this->createUser(['level' => 5]);
        $day = static fn (int $userId): array => $userId === $late
            ? ['events' => [['status' => 'scheduled', 'start' => '08:30'], ['status' => 'completed', 'start' => '07:00']], 'summary' => ['to_schedule' => 2, 'overdue' => 3],
                'overdue' => [['kind' => 'event', 'id' => 1], ['kind' => 'ticket', 'id' => 2]]]
            : ['events' => [], 'summary' => ['to_schedule' => 0, 'overdue' => 0], 'overdue' => []];

        self::assertSame(0, $this->push([$late, $empty], $day)->morning(new DateTimeImmutable('2026-11-27 06:59:00')));
        self::assertSame(1, $this->push([$late, $empty], $day)->morning(new DateTimeImmutable('2026-11-27 07:00:00')));
        self::assertSame([$late, 'Bom dia! 2 tarefas atrasadas', '1 compromisso hoje (o primeiro às 08:30) · 2 chamados para agendar.'], array_slice($this->sent[0], 0, 3));
        self::assertSame(2, $this->sent[0][3]['overdue'], 'conta a lista "overdue" quando ela vem');
        self::assertSame(0, $this->push([$late, $empty], $day)->morning(new DateTimeImmutable('2026-11-27 09:00:00')), 'uma vez por dia');
        self::assertSame(1, $this->push([$late], $day)->morning(new DateTimeImmutable('2026-11-28 07:01:00')), 'no dia seguinte avisa de novo');
    }

    public function testTextsWithoutCreatorNameOrOverdueList(): void
    {
        self::assertSame(['Alguém da equipe marcou uma tarefa para você', 'Vistoria · 01/12 às 14:00 · Lafayette'], MyDayPush::assignedText('  ', 'Vistoria', '2026-12-01 14:00:00', 'Lafayette'));
        self::assertSame(4, MyDayPush::overdueCount(['summary' => ['overdue' => 4]]));
        self::assertNull(MyDayPush::morningText(['events' => [], 'summary' => []]));
        self::assertSame(['Bom dia! Seu dia no Connect', 'Nenhum compromisso marcado hoje · 1 chamado para agendar.'], MyDayPush::morningText(['events' => [], 'summary' => ['to_schedule' => 1]]));
    }

    public function testRealTeamPushQueuesOnlyForWhoHasTheApp(): void
    {
        $boss = $this->createUser(['first_name' => 'Djalma', 'last_name' => 'Martins', 'level' => 5]);
        $withApp = $this->createUser(['level' => 5]);
        $withoutApp = $this->createUser(['level' => 5]);
        (new \Source\Services\Team\TeamPush($this->pdo))->register($withApp, 'meu-dia', 'ExponentPushToken[abcdefghijklmnop]', 'ios');
        $this->event($boss, $withApp, 'Ligar para o síndico', '2026-11-27 09:30:00');
        $this->event($boss, $withoutApp, 'Outra tarefa', '2026-11-27 10:00:00');

        (new MyDayPush($this->pdo))->assigned(new DateTimeImmutable('2026-11-26 22:00:00'));
        $rows = $this->pdo->query("SELECT user_id, app, title FROM team_push_queue ORDER BY id")->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['user_id' => $withApp, 'app' => 'meu-dia', 'title' => 'Djalma Martins marcou uma tarefa para você']], array_map(static fn (array $r): array => ['user_id' => (int)$r['user_id'], 'app' => $r['app'], 'title' => $r['title']], $rows));
    }
}
