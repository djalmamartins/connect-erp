<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MyDay;
use Source\Services\Erp\MyDayError;

/** Meu Dia (ADR 0628) no MariaDB de teste: o que entra no dia de cada pessoa, escopo e compromissos. */
final class MyDayTest extends TestCase
{
    private MyDay $service;
    private int $condo;
    private int $otherCondo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new MyDay($this->pdo, new CondominiumScope($this->pdo));
        $this->condo = $this->condominium('Condomínio Solar');
        $this->otherCondo = $this->condominium('Residencial Park');
    }

    public function testDayGathersEventsTicketsVisitsAndDeadlines(): void
    {
        $me = $this->user([], [$this->condo]);
        $other = $this->user([], [$this->condo]);
        $today = new DateTimeImmutable('2026-11-25'); // quarta-feira

        $eventId = $this->service->createEvent($me, ['title' => 'Planejamento do dia', 'date' => '2026-11-25', 'start' => '08:00', 'end' => '08:45', 'type' => 'task']);
        $this->service->createEvent($me, ['title' => 'Reunião com síndico', 'date' => '2026-11-27', 'start' => '14:00', 'condominium_id' => $this->condo]);
        $this->service->createEvent($other, ['title' => 'Não é meu', 'date' => '2026-11-25', 'start' => '09:00']);

        $urgent = $this->ticket('Portão quebrado', 'urgent', 'open', (int)$me->id, '2026-11-24 10:00:00');
        $routine = $this->ticket('Revisar contrato de limpeza', 'medium', 'in_progress', (int)$me->id, '2026-11-28 10:00:00');
        $this->ticket('Esperando orçamento', 'medium', 'waiting_customer', (int)$me->id, '2026-11-28 10:00:00');
        $this->ticket('Chamado de outra pessoa', 'urgent', 'open', (int)$other->id, '2026-11-25 10:00:00');
        $this->ticket('Já resolvido', 'urgent', 'resolved', (int)$me->id, '2026-11-25 10:00:00');

        $template = $this->template();
        $this->pdo->prepare("INSERT INTO inspection_plans (condominium_id, template_id, frequency, starts_on, status, responsible_id) VALUES (?, ?, 'biweekly', '2026-11-26', 'active', ?)")
            ->execute([$this->condo, $template, $me->id]);
        $this->pdo->prepare("INSERT INTO condominium_onboarding_items (condominium_id, category, title, status, expires_at, responsible_user_id) VALUES (?, 'seguranca', 'AVCB', 'regular', '2026-12-20', ?)")
            ->execute([$this->condo, $me->id]);

        $day = $this->service->day($me, $today, $today);
        self::assertSame(['Planejamento do dia'], array_column($day['events'], 'title'));
        self::assertSame(45, $day['events'][0]['minutes']);
        self::assertSame([$urgent, $routine], array_column($day['to_schedule'], 'id'), 'urgente primeiro; aguardando e resolvido ficam fora');
        self::assertTrue($day['to_schedule'][0]['overdue']);
        self::assertSame('Urgente', $day['to_schedule'][0]['tag']);
        self::assertSame(['Esperando orçamento'], array_column($day['waiting'], 'subject'));
        self::assertSame('2026-11-26', $day['visits'][0]['due_on']);
        self::assertSame('AVCB', $day['deadlines'][0]['title']);
        self::assertSame('Vence em 25 dias', $day['deadlines'][0]['label']);
        self::assertSame(['commitments' => 1, 'commitment_minutes' => 45, 'done' => 0, 'to_schedule' => 2, 'waiting' => 1, 'overdue' => 1, 'week_visits' => 1, 'week_condominiums' => 1], $day['summary']);

        // Semana de segunda a domingo, com o dia de hoje marcado e a contagem de cada dia.
        self::assertSame('2026-11-23', $day['week'][0]['date']);
        self::assertSame('Seg', $day['week'][0]['weekday']);
        self::assertTrue($day['week'][2]['today']);
        self::assertSame(1, $day['week'][2]['count']);
        self::assertSame(1, $day['week'][3]['count'], 'vistoria do dia 26');
        self::assertSame(1, $day['week'][4]['count'], 'reunião do dia 27');
        self::assertSame(1, $day['week'][5]['count'], 'prazo do chamado do dia 28');

        // Agendar um chamado tira ele de "Para agendar"; concluir conta como feito.
        $this->service->createEvent($me, ['title' => 'Ver portão', 'date' => '2026-11-25', 'start' => '10:00', 'ticket_id' => $urgent]);
        $this->service->setEventStatus($me, $eventId, 'completed');
        $day = $this->service->day($me, $today, $today);
        self::assertSame([$routine], array_column($day['to_schedule'], 'id'));
        self::assertSame($this->condo, $day['events'][1]['condominium_id'], 'o compromisso herda o condomínio do chamado');
        self::assertSame(1, $day['summary']['done']);
    }

    public function testOnlyTeamViewersSeeOrScheduleForOthers(): void
    {
        $me = $this->user([], [$this->condo]);
        $other = $this->user([], [$this->condo]);
        $manager = $this->user([MyDay::TEAM], [$this->condo]);

        self::assertSame((int)$me->id, (int)$this->service->owner($me, null)->id);
        $this->expectStatus(403, fn() => $this->service->owner($me, (int)$other->id));
        self::assertSame((int)$other->id, (int)$this->service->owner($manager, (int)$other->id)->id);

        $errors = $this->expectStatus(422, fn() => $this->service->createEvent($me, ['title' => 'Para outra', 'date' => '2026-11-25', 'start' => '09:00', 'assigned_to' => $other->id]));
        self::assertArrayHasKey('assigned_to', $errors);
        $id = $this->service->createEvent($manager, ['title' => 'Visita', 'date' => '2026-11-25', 'start' => '09:00', 'assigned_to' => $other->id]);
        self::assertSame('Visita', $this->service->day($other, new DateTimeImmutable('2026-11-25'))['events'][0]['title']);
        $this->expectStatus(404, fn() => $this->service->setEventStatus($me, $id, 'completed'));
        $this->service->setEventStatus($other, $id, 'completed');
    }

    public function testValidationAndCondominiumScope(): void
    {
        $me = $this->user([], [$this->condo]);
        $errors = $this->expectStatus(422, fn() => $this->service->createEvent($me, ['title' => 'x', 'date' => '25/11', 'start' => '9h', 'end' => '08:00', 'condominium_id' => $this->otherCondo]));
        self::assertSame(['title', 'date', 'start', 'condominium_id'], array_keys($errors));
        $errors = $this->expectStatus(422, fn() => $this->service->createEvent($me, ['title' => 'Visita', 'date' => '2026-11-25', 'start' => '10:00', 'end' => '09:00']));
        self::assertArrayHasKey('end', $errors);
        $this->expectStatus(404, fn() => $this->service->visitPanel($me, $this->otherCondo));
    }

    public function testVisitPanelShowsWhatToFollow(): void
    {
        $me = $this->user([], [$this->condo]);
        $this->ticket('Portão quebrado', 'urgent', 'open', null, '2026-11-26 10:00:00');
        $this->ticket('Bomba parada', 'high', 'open', null, '2026-11-26 10:00:00', 'Pendência de vistoria');
        $this->ticket('Lâmpada', 'low', 'resolved', null, '2026-11-26 10:00:00');
        $this->pdo->prepare("INSERT INTO condominium_onboarding_items (condominium_id, category, title, status, expires_at) VALUES (?, 'seguranca', 'AVCB', 'regular', '2026-12-01')")
            ->execute([$this->condo]);

        $panel = $this->service->visitPanel($me, $this->condo, new DateTimeImmutable('2026-11-25'));
        self::assertSame('Condomínio Solar', $panel['condominium']['name']);
        self::assertSame(['Portão quebrado', 'Bomba parada'], array_column($panel['tickets'], 'subject'));
        self::assertSame(2, $panel['urgent']);
        self::assertSame(['Bomba parada'], array_column($panel['pending_from_last_visit'], 'subject'));
        self::assertSame('Vence em 6 dias', $panel['documents'][0]['label']);
        self::assertNull($panel['last_visit']);
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
        return (int)$this->pdo->lastInsertId();
    }

    private function user(array $permissions, array $condominiums): User
    {
        $userId = $this->createUser(['level' => 5]);
        $slug = 'role_' . $userId;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 10)')->execute([$slug, $slug]);
        $roleId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$userId, $roleId]);
        foreach ($permissions as $permission) {
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES (?, ?, 'ERP')")->execute([$permission, $permission]);
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) SELECT ?, id FROM access_permissions WHERE slug = ?')->execute([$roleId, $permission]);
        }
        foreach ($condominiums as $condominium) {
            $this->pdo->prepare('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (?, ?)')->execute([$userId, $condominium]);
        }
        return (new User())->findById($userId);
    }

    private function ticket(string $subject, string $priority, string $status, ?int $assignee, string $due, ?string $category = null): int
    {
        $this->pdo->prepare("INSERT INTO studio_support_tickets (protocol, subject, message, area, origin, condominium_id, priority, status, category, assigned_to, due_at)
            VALUES (?, ?, 'Descrição do chamado', 'general', 'erp', ?, ?, ?, ?, ?, ?)")
            ->execute(['CH' . strtoupper(bin2hex(random_bytes(5))), $subject, $this->condo, $priority, $status, $category, $assignee, $due]);
        return (int)$this->pdo->lastInsertId();
    }

    private function template(): int
    {
        $this->pdo->exec("INSERT INTO inspection_templates (name, frequency) VALUES ('Ronda', 'biweekly')");
        return (int)$this->pdo->lastInsertId();
    }

    /** @return array<string, string> */
    private function expectStatus(int $status, callable $action): array
    {
        try {
            $action();
        } catch (MyDayError $error) {
            self::assertSame($status, $error->status, $error->getMessage());
            return $error->errors;
        }
        self::fail("Esperava erro {$status}.");
    }
}
