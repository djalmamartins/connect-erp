<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CollectionActionReminders;

/** Lembrete da próxima ação do atendimento de cobrança (ADR 0315): quem atendeu, ou a equipe, uma vez por contato. */
final class CollectionActionRemindersTest extends TestCase
{
    private int $alpha;
    private int $beta;
    /** @var list<int> */
    private array $units = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->exec("INSERT IGNORE INTO notifications_categories (title, uri) VALUES ('Studio', 'studio')");
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
            $ids[] = (int)$this->pdo->lastInsertId();
        }
        [$this->alpha, $this->beta] = $ids;
        foreach ([[$this->alpha, '101'], [$this->alpha, '102'], [$this->beta, '201']] as [$condo, $label]) {
            $this->pdo->prepare("INSERT INTO erp_units (condominium_id, number, type) VALUES (?, ?, 'apartamento')")->execute([$condo, $label]);
            $this->units[] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testRemindsTheAgentOnTheDayAndOnlyOnce(): void
    {
        $agent = $this->agent([$this->alpha]);
        $this->agent([$this->beta]);
        $today = '2026-11-20';
        $this->contact($this->units[0], $agent, '2026-11-20', 'promise', '2026-11-25');
        $this->contact($this->units[1], $agent, '2026-11-21');   // amanhã: ainda não

        $first = $this->remind($today);
        self::assertSame([1, 1, 1, 0], [$first['due'], $first['notified'], $first['to_agent'], $first['to_team']]);
        $note = $this->pdo->query('SELECT title, body, link, severity FROM notifications WHERE users_id = ' . $agent)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Ação de cobrança para hoje', 'info'], [$note['title'], $note['severity']]);
        self::assertStringContainsString('unidade 101 do Alpha marcada para hoje', $note['body']);
        self::assertStringContainsString('Promessa de pagamento para 25/11/2026', $note['body']);
        self::assertStringContainsString('/erp/cobranca/atendimento-cobranca?unidade=' . $this->units[0] . '#atender', $note['link']);

        self::assertSame(0, $this->remind($today)['notified'], 'O mesmo contato não é lembrado de novo.');
        self::assertSame(1, $this->remind('2026-11-21')['notified'], 'A unidade 102 no dia dela.');
    }

    public function testNewerContactReplacesTheScheduledAction(): void
    {
        $agent = $this->agent([$this->alpha]);
        $this->contact($this->units[0], $agent, '2026-11-20');
        $this->contact($this->units[0], $agent, '2026-11-27');   // atendeu de novo e remarcou
        self::assertSame(0, $this->remind('2026-11-20')['due']);
        self::assertSame(1, $this->remind('2026-11-27')['notified']);
    }

    public function testLateActionsWithinAWeekStillRemindAndOlderOnesDoNot(): void
    {
        $agent = $this->agent([$this->alpha]);
        $this->contact($this->units[0], $agent, '2026-11-15');
        $this->contact($this->units[1], $agent, '2026-11-01');
        $result = $this->remind('2026-11-20');
        self::assertSame([1, 1], [$result['due'], $result['notified']], 'Só o atraso de até 7 dias.');
        self::assertSame('Ação de cobrança atrasada', $this->pdo->query('SELECT title FROM notifications WHERE users_id = ' . $agent)->fetchColumn());
    }

    public function testFallsBackToTheTeamThatReachesTheCondominium(): void
    {
        $left = $this->agent([$this->alpha]);
        $this->pdo->prepare("UPDATE users SET status = 'registered' WHERE id = ?")->execute([$left]);   // saiu da Connect
        $teamAlpha = $this->agent([$this->alpha]);
        $teamBeta = $this->agent([$this->beta]);
        $plain = $this->createUser(['level' => 2, 'status' => 'confirmed']);   // sem a permissão
        (new CondominiumScope($this->pdo))->grant($plain, $this->alpha);
        $this->contact($this->units[0], $left, '2026-11-20');
        $this->contact($this->units[2], null, '2026-11-20');

        $result = $this->remind('2026-11-20');
        self::assertSame([2, 0, 2, 2], [$result['due'], $result['to_agent'], $result['to_team'], $result['notified']]);
        $count = fn (int $user): int => (int)$this->pdo->query('SELECT COUNT(*) FROM notifications WHERE users_id = ' . $user)->fetchColumn();
        self::assertSame([0, 1, 1, 0], [$count($left), $count($teamAlpha), $count($teamBeta), $count($plain)], 'Cada equipe só do próprio condomínio.');
    }

    private function remind(string $today): array
    {
        return (new CollectionActionReminders($this->pdo, new DateTimeImmutable($today)))->run();
    }

    private function contact(int $unit, ?int $by, string $nextOn, string $outcome = 'no_answer', ?string $promiseOn = null): void
    {
        $condo = (int)$this->pdo->query('SELECT condominium_id FROM erp_units WHERE id = ' . $unit)->fetchColumn();
        $this->pdo->prepare("INSERT INTO erp_collection_contacts (condominium_id, unit_id, channel, outcome, promise_on, next_action_on, note, open_cents, created_by)
            VALUES (?, ?, 'phone', ?, ?, ?, 'Contato de teste.', 50000, ?)")->execute([$condo, $unit, $outcome, $promiseOn, $nextOn, $by]);
    }

    /** Conta confirmada com erp.collections.manage pelo papel, só nos condomínios dados. */
    private function agent(array $condominiums): int
    {
        $id = $this->createUser(['level' => 2, 'status' => 'confirmed']);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        $slug = 'cobranca_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Atender cobrança', 'erp.collections.manage', 'ERP')")->execute();
        $permission = (int)$this->pdo->query("SELECT id FROM access_permissions WHERE slug = 'erp.collections.manage'")->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        return $id;
    }
}
