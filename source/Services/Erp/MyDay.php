<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Inspections\Schedule;
use Source\Services\Support\TicketService;
use Source\Support\Access;
use Source\Support\Audit;

/**
 * Meu Dia (ADR 0630): o dia de trabalho de uma pessoa da equipe, montado do que o sistema já tem.
 *
 * - Compromissos: a agenda que já existe (studio_calendar_events), do responsável ou, sem responsável, de quem criou.
 * - Para agendar: chamados atribuídos à pessoa, em aberto ou em atendimento, sem compromisso marcado.
 * - Aguardando: chamados atribuídos à pessoa que esperam o cliente.
 * - Vistorias: planos dos quais a pessoa é responsável (sem responsável: quem criou o plano), na data devida.
 * - Vencimentos: itens do checklist de chegada sob responsabilidade da pessoa, com prazo ou validade nos próximos 45 dias.
 * - Painel da visita: o que acompanhar num condomínio (chamados abertos, vencimentos, pendências da última vistoria).
 *
 * - Ficou para trás (ADR 0658): compromisso/tarefa de dia anterior ainda não concluído, chamado do SAC com prazo vencido,
 *   vistoria atrasada e item do checklist vencido. Fica em vermelho no dia original e é levado para hoje até ser
 *   concluído, cancelado ou o chamado ser resolvido; aí volta ao normal. Olha até OVERDUE_DAYS dias para trás.
 * - Sem responsável (ADR 0658): chamados do SAC em aberto sem ninguém atribuído, para quem atende (support.manage).
 *
 * Cada pessoa vê o próprio dia; ver o de outra pessoa ou marcar compromisso para ela exige erp.myday.team.
 * Nada aqui muda chamado, vistoria ou checklist: cada linha leva à tela que resolve.
 */
final class MyDay
{
    public const TEAM = 'erp.myday.team';
    public const TYPES = ['meeting' => 'Compromisso', 'task' => 'Tarefa', 'deadline' => 'Prazo', 'support' => 'Atendimento'];
    private const OPEN = ['open', 'in_progress'];
    private const CLOSED = ['resolved', 'closed'];
    private const PRIORITY_ORDER = ['urgent' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];
    private const SOON_DAYS = 45;
    /** Até quantos dias para trás um compromisso não concluído ainda é levado para hoje (ADR 0658). */
    public const OVERDUE_DAYS = 30;
    /** Quem atende chamados do SAC vê os que estão sem responsável (ADR 0631/0658). */
    public const SUPPORT = 'support.manage';
    private const UNASSIGNED_LIMIT = 50;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    public function canSeeTeam(User $viewer): bool
    {
        return Access::can(self::TEAM, $viewer);
    }

    /**
     * Pessoa cujo dia será mostrado: o próprio usuário ou, com erp.myday.team, alguém da equipe.
     *
     * @throws MyDayError 403 sem permissão, 404 pessoa inexistente
     */
    public function owner(User $viewer, ?int $personId): User
    {
        if ($personId === null || $personId === (int)$viewer->id) {
            return $viewer;
        }
        if (!$this->canSeeTeam($viewer)) {
            throw new MyDayError('person', 'Você só pode ver o seu próprio dia.', 403);
        }
        $person = (new User())->findById($personId);
        if (!$person || (string)$person->status === 'trash') {
            throw new MyDayError('person', 'Pessoa não encontrada.', 404);
        }
        return $person;
    }

    /** @return list<array{id:int,name:string}> quem aparece no seletor de pessoa (a equipe) */
    public function team(): array
    {
        $rows = $this->pdo->query("SELECT id, NULLIF(TRIM(CONCAT(first_name, ' ', last_name)), '') AS name FROM users
            WHERE status <> 'trash' AND level >= 5 ORDER BY first_name, last_name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r): array => ['id' => (int)$r['id'], 'name' => (string)($r['name'] ?? ('#' . $r['id']))], $rows);
    }

    /**
     * O dia inteiro da pessoa. $date é o dia mostrado; a semana é a de segunda a domingo que contém o dia.
     *
     * @return array<string, mixed>
     */
    public function day(User $owner, DateTimeImmutable $date, ?DateTimeImmutable $today = null): array
    {
        $date = $date->setTime(0, 0);
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $weekStart = $date->modify('monday this week');
        $weekEnd = $weekStart->modify('+6 days');
        // Faixa dos dias (pedido do Djalma, 22/11): o dia mostrado no meio, 3 antes e 3 depois.
        $stripStart = $date->modify('-3 days');
        $stripEnd = $date->modify('+3 days');
        $userId = (int)$owner->id;

        $allEvents = $this->events($userId, min($weekStart, $stripStart), max($weekEnd, $stripEnd), $today);
        $events = array_values(array_filter($allEvents, static fn(array $e): bool => substr($e['starts_at'], 0, 10) >= $weekStart->format('Y-m-d') && substr($e['starts_at'], 0, 10) <= $weekEnd->format('Y-m-d')));
        $tickets = $this->tickets($userId, $today);
        $toSchedule = array_values(array_filter($tickets, static fn(array $t): bool => in_array($t['status'], self::OPEN, true) && $t['scheduled_event_id'] === null));
        $waiting = array_values(array_filter($tickets, static fn(array $t): bool => $t['status'] === 'waiting_customer'));
        $visits = $this->visits($userId, $today, max($weekEnd, $stripEnd, $today->modify('+14 days')));
        $deadlines = $this->deadlines($userId, $today);
        $overdue = $this->overdueItems($userId, $today, $tickets, $visits, $deadlines);
        $unassigned = $this->unassigned($owner, $today);

        $dayKey = $date->format('Y-m-d');
        $dayEvents = array_values(array_filter($events, static fn(array $e): bool => substr($e['starts_at'], 0, 10) === $dayKey));
        $dayVisits = array_values(array_filter($visits, static fn(array $v): bool => $v['due_on'] === $dayKey || ($dayKey === $today->format('Y-m-d') && $v['overdue'])));
        $dayDeadlines = array_values(array_filter($tickets, static fn(array $t): bool => $t['due_on'] === $dayKey && in_array($t['status'], self::OPEN, true)));

        $lateByDay = array_count_values(array_column($overdue, 'due_on'));
        $dayInfo = static function (DateTimeImmutable $d) use ($allEvents, $visits, $tickets, $today, $dayKey, $lateByDay, $overdue): array {
            $key = $d->format('Y-m-d');
            $isToday = $key === $today->format('Y-m-d');
            $count = count(array_filter($allEvents, static fn(array $e): bool => substr($e['starts_at'], 0, 10) === $key && $e['status'] !== 'cancelled'))
                + count(array_filter($visits, static fn(array $v): bool => $v['due_on'] === $key))
                + count(array_filter($tickets, static fn(array $t): bool => $t['due_on'] === $key && in_array($t['status'], self::OPEN, true)));
            return ['date' => $key, 'weekday' => self::weekday($d), 'day' => (int)$d->format('j'), 'count' => $count,
                'today' => $isToday, 'selected' => $key === $dayKey,
                // Vermelho no círculo: o que daquele dia ficou para trás; hoje mostra quantos foram levados para ele.
                'overdue' => $lateByDay[$key] ?? 0, 'carried' => $isToday ? count($overdue) : 0];
        };
        $week = [];
        $strip = [];
        for ($i = 0; $i < 7; $i++) {
            $week[] = $dayInfo($weekStart->modify("+{$i} days"));
            $strip[] = $dayInfo($stripStart->modify("+{$i} days"));
        }

        $scheduled = array_filter($dayEvents, static fn(array $e): bool => $e['status'] !== 'cancelled');
        $minutes = array_sum(array_map(static fn(array $e): int => $e['minutes'], $scheduled));
        $weekVisits = array_values(array_filter($visits, static fn(array $v): bool => $v['due_on'] >= $weekStart->format('Y-m-d') && $v['due_on'] <= $weekEnd->format('Y-m-d') || $v['overdue']));

        return [
            'date' => $dayKey,
            'today' => $today->format('Y-m-d'),
            'person' => ['id' => $userId, 'name' => self::name($owner)],
            'summary' => [
                'commitments' => count($scheduled),
                'commitment_minutes' => $minutes,
                'done' => count(array_filter($scheduled, static fn(array $e): bool => $e['status'] === 'completed')),
                'to_schedule' => count($toSchedule),
                'waiting' => count($waiting),
                'overdue' => count($overdue),
                'overdue_oldest' => $overdue[0]['due_on'] ?? null,
                'unassigned' => count($unassigned),
                'week_visits' => count($weekVisits),
                'week_condominiums' => count(array_unique(array_column($weekVisits, 'condominium_id'))),
            ],
            'week' => $week,
            'strip' => $strip,
            'events' => $dayEvents,
            'week_events' => $events,
            'day_visits' => $dayVisits,
            'day_deadlines' => $dayDeadlines,
            'to_schedule' => $toSchedule,
            'waiting' => $waiting,
            'visits' => $visits,
            'deadlines' => $deadlines,
            'overdue' => $overdue,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Só o que ficou para trás (ADR 0658), para o selo do menu, o alerta das 7h e GET /api/v1/my-day/overdue.
     *
     * @return array{count:int, oldest:?string, items:list<array<string, mixed>>}
     */
    public function overdue(User $owner, ?DateTimeImmutable $today = null): array
    {
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $userId = (int)$owner->id;
        $items = $this->overdueItems($userId, $today, $this->tickets($userId, $today),
            $this->visits($userId, $today, $today), $this->deadlines($userId, $today));
        return ['count' => count($items), 'oldest' => $items[0]['due_on'] ?? null, 'items' => $items];
    }

    /**
     * Painel da visita: o que acompanhar num condomínio do escopo de quem vê.
     *
     * @return array<string, mixed>
     * @throws MyDayError 404 fora do escopo
     */
    public function visitPanel(User $viewer, int $condominiumId, ?DateTimeImmutable $today = null): array
    {
        if (!$this->scope->canAccess($viewer, $condominiumId)) {
            throw new MyDayError('condominium_id', 'Condomínio não encontrado no seu acesso.', 404);
        }
        $today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $condo = $this->pdo->prepare("SELECT c.id, c.name, CONCAT_WS(', ', NULLIF(CONCAT_WS(', ', c.street, c.number), ''), c.district, c.city) AS address FROM condominiums c WHERE c.id = ?");
        $condo->execute([$condominiumId]);
        $row = $condo->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new MyDayError('condominium_id', 'Condomínio não encontrado.', 404);
        }

        $tickets = $this->pdo->prepare("SELECT id, protocol, subject, priority, status, category, due_at FROM studio_support_tickets
            WHERE condominium_id = ? AND status IN ('open', 'in_progress', 'waiting_customer')
            ORDER BY FIELD(priority, 'urgent', 'high', 'medium', 'low'), due_at");
        $tickets->execute([$condominiumId]);
        $open = array_map(fn(array $t): array => $this->ticketRow($t, $today), $tickets->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $items = $this->pdo->prepare("SELECT id, title, status, due_date, expires_at FROM condominium_onboarding_items
            WHERE condominium_id = ? AND status <> 'not_applicable'
              AND ((status IN ('pending', 'action_required', 'in_progress') AND due_date IS NOT NULL AND due_date <= ?)
                OR (status = 'regular' AND expires_at IS NOT NULL AND expires_at <= ?))
            ORDER BY COALESCE(expires_at, due_date)");
        $limit = $today->modify('+' . self::SOON_DAYS . ' days')->format('Y-m-d');
        $items->execute([$condominiumId, $limit, $limit]);
        $documents = array_map(fn(array $i): array => $this->deadlineRow($i, $today), $items->fetchAll(PDO::FETCH_ASSOC) ?: []);

        $last = $this->pdo->prepare("SELECT i.id, i.template_name, i.completed_at, i.summary,
                (SELECT COUNT(*) FROM inspection_answers a WHERE a.inspection_id = i.id AND a.result = 'issue') AS issues
            FROM inspections i WHERE i.condominium_id = ? AND i.status = 'completed' ORDER BY i.completed_at DESC LIMIT 1");
        $last->execute([$condominiumId]);
        $lastVisit = $last->fetch(PDO::FETCH_ASSOC) ?: null;

        $byCategory = static fn(string $category): array => array_values(array_filter($open, static fn(array $t): bool => $t['category'] === $category));
        return [
            'condominium' => ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'address' => (string)($row['address'] ?? '')],
            'tickets' => $open,
            'urgent' => count(array_filter($open, static fn(array $t): bool => in_array($t['priority'], ['urgent', 'high'], true))),
            'documents' => $documents,
            'pending_from_last_visit' => $byCategory('Pendência de vistoria'),
            'last_visit' => $lastVisit === null ? null : [
                'id' => (int)$lastVisit['id'], 'template' => (string)$lastVisit['template_name'],
                'completed_at' => (string)$lastVisit['completed_at'], 'issues' => (int)$lastVisit['issues'],
            ],
        ];
    }

    /**
     * Novo compromisso na agenda. Para outra pessoa, exige erp.myday.team. Com ticket_id, o chamado sai de "Para agendar".
     *
     * @param array<string, mixed> $input title, date, start, end, type, condominium_id, ticket_id, description, assigned_to
     * @throws MyDayError
     */
    public function createEvent(User $viewer, array $input): int
    {
        $text = static fn(string $key, int $max): string => mb_substr(trim(strip_tags((string)($input[$key] ?? ''))), 0, $max);
        $int = static fn(string $key): ?int => ($v = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$v;
        $errors = [];
        $title = $text('title', 180);
        if (mb_strlen($title) < 3) {
            $errors['title'] = 'Diga em poucas palavras o que é (pelo menos 3 letras).';
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($input['date'] ?? ''));
        if ($date === false) {
            $errors['date'] = 'Escolha o dia.';
        }
        $start = self::time((string)($input['start'] ?? ''));
        $end = self::time((string)($input['end'] ?? ''));
        if ($start === null) {
            $errors['start'] = 'Informe a hora de início (ex.: 09:00).';
        }
        if (($input['end'] ?? '') !== '' && $end === null) {
            $errors['end'] = 'Hora de fim inválida.';
        }
        if ($start !== null && $end !== null && $end <= $start) {
            $errors['end'] = 'O fim precisa ser depois do início.';
        }
        $type = array_key_exists((string)($input['type'] ?? ''), self::TYPES) ? (string)$input['type'] : 'meeting';
        $condominiumId = $int('condominium_id');
        if ($condominiumId !== null && !$this->scope->canAccess($viewer, $condominiumId)) {
            $errors['condominium_id'] = 'Condomínio indisponível para a sua conta.';
        }
        $assignee = $int('assigned_to') ?? (int)$viewer->id;
        if ($assignee !== (int)$viewer->id) {
            if (!$this->canSeeTeam($viewer)) {
                $errors['assigned_to'] = 'Você só pode marcar compromissos para você.';
            } elseif (!in_array($assignee, array_column($this->team(), 'id'), true)) {
                $errors['assigned_to'] = 'Escolha alguém da equipe.';
            }
        }
        $ticketId = $int('ticket_id');
        if ($ticketId !== null) {
            $ticket = $this->pdo->prepare('SELECT id, condominium_id, assigned_to FROM studio_support_tickets WHERE id = ?');
            $ticket->execute([$ticketId]);
            $found = $ticket->fetch(PDO::FETCH_ASSOC);
            if (!is_array($found) || ((int)$found['assigned_to'] !== $assignee && !$this->canSeeTeam($viewer))) {
                $errors['ticket_id'] = 'Chamado não encontrado entre os seus.';
            } elseif ($condominiumId === null && $found['condominium_id'] !== null) {
                $condominiumId = (int)$found['condominium_id'];
            }
        }
        if ($errors !== []) {
            throw MyDayError::invalid($errors);
        }
        $day = $date->format('Y-m-d');
        $this->pdo->prepare('INSERT INTO studio_calendar_events (title, description, starts_at, ends_at, type, status, assigned_to, condominium_id, ticket_id, created_by)
            VALUES (?, ?, ?, ?, ?, \'scheduled\', ?, ?, ?, ?)')->execute([
            $title, $text('description', 2000) ?: null, "{$day} {$start}:00", $end !== null ? "{$day} {$end}:00" : null, $type,
            $assignee, $condominiumId, $ticketId, (int)$viewer->id,
        ]);
        $id = (int)$this->pdo->lastInsertId();
        Audit::record('create', 'studio_calendar_events', $id, null, ['title' => $title, 'starts_at' => "{$day} {$start}:00",
            'assigned_to' => $assignee, 'condominium_id' => $condominiumId, 'ticket_id' => $ticketId]);
        return $id;
    }

    /**
     * Concluir, cancelar ou reabrir um compromisso: o responsável, quem criou, ou quem vê a equipe.
     *
     * @throws MyDayError
     */
    public function setEventStatus(User $viewer, int $eventId, string $status): void
    {
        if (!in_array($status, ['scheduled', 'completed', 'cancelled'], true)) {
            throw MyDayError::invalid(['status' => 'Situação inválida.']);
        }
        $stmt = $this->pdo->prepare('SELECT id, status, assigned_to, created_by FROM studio_calendar_events WHERE id = ?');
        $stmt->execute([$eventId]);
        $event = $stmt->fetch(PDO::FETCH_ASSOC);
        $mine = is_array($event) && in_array((int)$viewer->id, [(int)$event['assigned_to'], (int)$event['created_by']], true);
        if (!is_array($event) || (!$mine && !$this->canSeeTeam($viewer))) {
            throw new MyDayError('event', 'Compromisso não encontrado.', 404);
        }
        if ($event['status'] === $status) {
            return;
        }
        $this->pdo->prepare('UPDATE studio_calendar_events SET status = ? WHERE id = ?')->execute([$status, $eventId]);
        Audit::record('update', 'studio_calendar_events', $eventId, ['status' => $event['status']], ['status' => $status]);
    }

    /* ---------------------------------------------------------------- consultas */

    /** @return list<array<string, mixed>> */
    private function events(int $userId, DateTimeImmutable $from, DateTimeImmutable $to, DateTimeImmutable $today): array
    {
        $todayKey = $today->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT e.id, e.title, e.description, e.starts_at, e.ends_at, e.type, e.status, e.condominium_id, e.ticket_id,
                c.name AS condominium, t.protocol, t.status AS ticket_status
            FROM studio_calendar_events e
            LEFT JOIN condominiums c ON c.id = e.condominium_id
            LEFT JOIN studio_support_tickets t ON t.id = e.ticket_id
            WHERE (e.assigned_to = :user OR (e.assigned_to IS NULL AND e.created_by = :creator))
              AND e.starts_at >= :from AND e.starts_at < :to
            ORDER BY e.starts_at, e.id");
        $stmt->execute(['user' => $userId, 'creator' => $userId, 'from' => $from->format('Y-m-d 00:00:00'), 'to' => $to->modify('+1 day')->format('Y-m-d 00:00:00')]);
        return array_map(static function (array $e) use ($todayKey): array {
            $start = strtotime((string)$e['starts_at']);
            $end = $e['ends_at'] !== null ? strtotime((string)$e['ends_at']) : null;
            return [
                'id' => (int)$e['id'], 'title' => (string)$e['title'], 'description' => (string)($e['description'] ?? ''),
                'starts_at' => (string)$e['starts_at'], 'ends_at' => $e['ends_at'] !== null ? (string)$e['ends_at'] : null,
                'start' => date('H:i', $start), 'end' => $end !== null ? date('H:i', $end) : null,
                'minutes' => $end !== null && $end > $start ? (int)(($end - $start) / 60) : 30,
                'type' => (string)$e['type'], 'type_label' => self::TYPES[$e['type']] ?? 'Compromisso', 'status' => (string)$e['status'],
                'condominium_id' => $e['condominium_id'] !== null ? (int)$e['condominium_id'] : null, 'condominium' => $e['condominium'] !== null ? (string)$e['condominium'] : null,
                'ticket_id' => $e['ticket_id'] !== null ? (int)$e['ticket_id'] : null, 'protocol' => $e['protocol'] !== null ? (string)$e['protocol'] : null,
                // Ficou para trás (ADR 0658): dia passou, não foi concluído nem cancelado e o chamado dele não foi resolvido.
                'overdue' => $e['status'] === 'scheduled' && substr((string)$e['starts_at'], 0, 10) < $todayKey
                    && !in_array((string)($e['ticket_status'] ?? ''), self::CLOSED, true),
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Tudo o que ficou para trás e é levado para hoje (ADR 0658), do mais antigo para o mais novo.
     * Um chamado agendado num compromisso atrasado aparece uma vez só (pelo compromisso); o chamado da vistoria
     * atrasada também (pela vistoria).
     *
     * @param list<array<string, mixed>> $tickets @param list<array<string, mixed>> $visits @param list<array<string, mixed>> $deadlines
     * @return list<array<string, mixed>>
     */
    private function overdueItems(int $userId, DateTimeImmutable $today, array $tickets, array $visits, array $deadlines): array
    {
        $todayKey = $today->format('Y-m-d');
        $late = static fn(string $dueOn): int => max(1, (int)(new DateTimeImmutable($dueOn))->diff($today)->format('%a'));
        $item = static fn(string $kind, int $id, string $title, string $dueOn, ?int $condoId, ?string $condo, ?int $ticketId, ?string $protocol, string $status, ?string $time = null): array => [
            'kind' => $kind, 'id' => $id, 'title' => $title, 'due_on' => $dueOn, 'days_late' => $late($dueOn), 'time' => $time,
            'condominium_id' => $condoId, 'condominium' => $condo, 'ticket_id' => $ticketId, 'protocol' => $protocol, 'status' => $status,
        ];

        $stmt = $this->pdo->prepare("SELECT e.id, e.title, e.starts_at, e.condominium_id, c.name AS condominium, e.ticket_id, t.protocol
            FROM studio_calendar_events e
            LEFT JOIN condominiums c ON c.id = e.condominium_id
            LEFT JOIN studio_support_tickets t ON t.id = e.ticket_id
            WHERE (e.assigned_to = :user OR (e.assigned_to IS NULL AND e.created_by = :creator))
              AND e.status = 'scheduled' AND e.starts_at >= :from AND e.starts_at < :today
              AND (t.id IS NULL OR t.status NOT IN ('resolved', 'closed'))
            ORDER BY e.starts_at, e.id");
        $stmt->execute(['user' => $userId, 'creator' => $userId, 'from' => $today->modify('-' . self::OVERDUE_DAYS . ' days')->format('Y-m-d 00:00:00'), 'today' => $todayKey . ' 00:00:00']);
        $items = [];
        $covered = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $e) {
            $items[] = $item('event', (int)$e['id'], (string)$e['title'], substr((string)$e['starts_at'], 0, 10),
                $e['condominium_id'] !== null ? (int)$e['condominium_id'] : null, $e['condominium'] !== null ? (string)$e['condominium'] : null,
                $e['ticket_id'] !== null ? (int)$e['ticket_id'] : null, $e['protocol'] !== null ? (string)$e['protocol'] : null,
                'scheduled', date('H:i', (int)strtotime((string)$e['starts_at'])));
            if ($e['ticket_id'] !== null) {
                $covered[(int)$e['ticket_id']] = true;
            }
        }
        $lateVisitCondos = [];
        foreach ($visits as $v) {
            if ($v['overdue']) {
                $items[] = $item('visit', $v['plan_id'], 'Vistoria ' . mb_strtolower($v['frequency_label']) . ' – ' . $v['condominium'], $v['due_on'],
                    $v['condominium_id'], $v['condominium'], null, null, $v['open_inspection_id'] !== null ? 'in_progress' : 'due');
                $lateVisitCondos[$v['condominium_id']] = true;
            }
        }
        foreach ($tickets as $t) {
            if (!$t['overdue'] || !in_array($t['status'], self::OPEN, true) || isset($covered[$t['id']])
                || ($t['category'] === 'Vistoria' && isset($lateVisitCondos[$t['condominium_id']]))) {
                continue;
            }
            $items[] = $item('ticket', $t['id'], $t['subject'], $t['due_on'], $t['condominium_id'], $t['condominium'], $t['id'], $t['protocol'], $t['status']);
        }
        foreach ($deadlines as $d) {
            if ($d['days'] < 0) {
                $items[] = $item('deadline', $d['id'], $d['title'], $d['date'], $d['condominium_id'], $d['condominium'], null, null, $d['label']);
            }
        }
        usort($items, static fn(array $a, array $b): int => [$a['due_on'], (string)$a['time'], $a['kind'], $a['id']] <=> [$b['due_on'], (string)$b['time'], $b['kind'], $b['id']]);
        return $items;
    }

    /**
     * Chamados do SAC em aberto sem responsável, no escopo de condomínios da pessoa, só para quem atende (ADR 0658).
     *
     * @return list<array<string, mixed>>
     */
    private function unassigned(User $owner, DateTimeImmutable $today): array
    {
        if (!Access::can(self::SUPPORT, $owner)) {
            return [];
        }
        $ids = $this->scope->allowedIds($owner);
        $in = $ids === [] ? '' : ' OR t.condominium_id IN (' . implode(',', array_map('intval', $ids)) . ')';
        $rows = $this->pdo->query("SELECT t.id, t.protocol, t.subject, t.priority, t.status, t.category, t.due_at, t.condominium_id, c.name AS condominium
            FROM studio_support_tickets t LEFT JOIN condominiums c ON c.id = t.condominium_id
            WHERE t.assigned_to IS NULL AND t.status IN ('open', 'in_progress') AND (t.condominium_id IS NULL{$in})
            ORDER BY FIELD(t.priority, 'urgent', 'high', 'medium', 'low'), t.due_at IS NULL, t.due_at, t.id
            LIMIT " . self::UNASSIGNED_LIMIT)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(fn(array $t): array => $this->ticketRow($t, $today) + [
            'condominium_id' => $t['condominium_id'] !== null ? (int)$t['condominium_id'] : null,
            'condominium' => $t['condominium'] !== null ? (string)$t['condominium'] : null,
        ], $rows);
    }

    /** @return list<array<string, mixed>> chamados atribuídos à pessoa ainda não resolvidos, por prioridade e prazo */
    private function tickets(int $userId, DateTimeImmutable $today): array
    {
        $stmt = $this->pdo->prepare("SELECT t.id, t.protocol, t.subject, t.priority, t.status, t.category, t.due_at, t.condominium_id, c.name AS condominium,
                (SELECT MIN(e.id) FROM studio_calendar_events e WHERE e.ticket_id = t.id AND e.status = 'scheduled') AS scheduled_event_id
            FROM studio_support_tickets t LEFT JOIN condominiums c ON c.id = t.condominium_id
            WHERE t.assigned_to = ? AND t.status IN ('open', 'in_progress', 'waiting_customer')");
        $stmt->execute([$userId]);
        $rows = array_map(fn(array $t): array => $this->ticketRow($t, $today) + [
            'condominium_id' => $t['condominium_id'] !== null ? (int)$t['condominium_id'] : null,
            'condominium' => $t['condominium'] !== null ? (string)$t['condominium'] : null,
            'scheduled_event_id' => $t['scheduled_event_id'] !== null ? (int)$t['scheduled_event_id'] : null,
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
        usort($rows, static fn(array $a, array $b): int => [self::PRIORITY_ORDER[$a['priority']] ?? 9, $a['due_at']] <=> [self::PRIORITY_ORDER[$b['priority']] ?? 9, $b['due_at']]);
        return $rows;
    }

    /** @return array<string, mixed> */
    private function ticketRow(array $t, DateTimeImmutable $today): array
    {
        $due = (string)$t['due_at'];
        $tag = match (true) {
            $t['priority'] === 'urgent' => 'Urgente',
            $t['priority'] === 'high' => 'Importante',
            in_array((string)($t['category'] ?? ''), ['Vistoria'], true) => 'Rotina',
            default => 'Normal',
        };
        return [
            'id' => (int)$t['id'], 'protocol' => (string)$t['protocol'], 'subject' => (string)$t['subject'],
            'priority' => (string)$t['priority'], 'priority_label' => TicketService::PRIORITY_LABELS[$t['priority']] ?? 'Média', 'tag' => $tag,
            'status' => (string)$t['status'], 'status_label' => TicketService::STATUS_LABELS[$t['status']] ?? (string)$t['status'],
            'category' => (string)($t['category'] ?? ''), 'due_at' => $due, 'due_on' => substr($due, 0, 10),
            'overdue' => $due !== '' && substr($due, 0, 10) < $today->format('Y-m-d'),
            'due_today' => substr($due, 0, 10) === $today->format('Y-m-d'),
        ];
    }

    /** @return list<array<string, mixed>> vistorias dos planos da pessoa até $until, as vencidas primeiro */
    private function visits(int $userId, DateTimeImmutable $today, DateTimeImmutable $until): array
    {
        $stmt = $this->pdo->prepare("SELECT p.id, p.condominium_id, c.name AS condominium, CONCAT_WS(', ', NULLIF(CONCAT_WS(', ', c.street, c.number), ''), c.district, c.city) AS address, p.template_id, t.name AS template, p.frequency, p.starts_on,
                (SELECT MAX(i.completed_at) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'completed') AS last_completed_at,
                (SELECT MAX(i.id) FROM inspections i WHERE i.plan_id = p.id AND i.status = 'in_progress') AS open_inspection_id
            FROM inspection_plans p
            JOIN condominiums c ON c.id = p.condominium_id AND c.status = 'active'
            JOIN inspection_templates t ON t.id = p.template_id AND t.status = 'active'
            WHERE p.status = 'active' AND (p.responsible_id = :user OR (p.responsible_id IS NULL AND p.created_by = :creator))");
        $stmt->execute(['user' => $userId, 'creator' => $userId]);
        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
            $last = $p['last_completed_at'] ? new DateTimeImmutable((string)$p['last_completed_at']) : null;
            $due = Schedule::nextDue((string)$p['frequency'], new DateTimeImmutable((string)$p['starts_on']), $last);
            if ($due > $until) {
                continue;
            }
            $rows[] = [
                'plan_id' => (int)$p['id'], 'condominium_id' => (int)$p['condominium_id'], 'condominium' => (string)$p['condominium'],
                'address' => (string)($p['address'] ?? ''), 'template_id' => (int)$p['template_id'], 'template' => (string)$p['template'],
                'frequency' => (string)$p['frequency'], 'frequency_label' => Schedule::label((string)$p['frequency']),
                'due_on' => $due->format('Y-m-d'), 'overdue' => $due < $today,
                'last_completed_at' => $p['last_completed_at'] !== null ? (string)$p['last_completed_at'] : null,
                'open_inspection_id' => $p['open_inspection_id'] !== null ? (int)$p['open_inspection_id'] : null,
            ];
        }
        usort($rows, static fn(array $a, array $b): int => [$a['due_on'], $a['condominium']] <=> [$b['due_on'], $b['condominium']]);
        return $rows;
    }

    /** @return list<array<string, mixed>> vencimentos do checklist de chegada sob responsabilidade da pessoa */
    private function deadlines(int $userId, DateTimeImmutable $today): array
    {
        $limit = $today->modify('+' . self::SOON_DAYS . ' days')->format('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT i.id, i.title, i.status, i.due_date, i.expires_at, i.condominium_id, c.name AS condominium
            FROM condominium_onboarding_items i JOIN condominiums c ON c.id = i.condominium_id AND c.status = 'active'
            WHERE i.responsible_user_id = ? AND i.status <> 'not_applicable'
              AND ((i.status IN ('pending', 'action_required', 'in_progress') AND i.due_date IS NOT NULL AND i.due_date <= ?)
                OR (i.status = 'regular' AND i.expires_at IS NOT NULL AND i.expires_at <= ?))
            ORDER BY COALESCE(i.expires_at, i.due_date) LIMIT 50");
        $stmt->execute([$userId, $limit, $limit]);
        return array_map(fn(array $i): array => $this->deadlineRow($i, $today) + [
            'condominium_id' => (int)$i['condominium_id'], 'condominium' => (string)$i['condominium'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return array<string, mixed> */
    private function deadlineRow(array $i, DateTimeImmutable $today): array
    {
        $date = (string)($i['status'] === 'regular' ? $i['expires_at'] : $i['due_date']);
        $days = (int)$today->diff(new DateTimeImmutable($date))->format('%r%a');
        return [
            'id' => (int)$i['id'], 'title' => (string)$i['title'], 'date' => $date, 'days' => $days,
            'label' => $i['status'] === 'regular'
                ? ($days < 0 ? 'Venceu há ' . abs($days) . ' dias' : ($days === 0 ? 'Vence hoje' : "Vence em {$days} dias"))
                : ($days < 0 ? 'Prazo passou há ' . abs($days) . ' dias' : ($days === 0 ? 'Prazo hoje' : "Prazo em {$days} dias")),
        ];
    }

    private static function time(string $value): ?string
    {
        return preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', trim($value)) ? trim($value) : null;
    }

    public static function weekday(DateTimeImmutable $date): string
    {
        return ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'][(int)$date->format('w')];
    }

    public static function longDate(DateTimeImmutable $date): string
    {
        $days = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        return ucfirst($days[(int)$date->format('w')]) . ', ' . $date->format('j') . ' de ' . $months[(int)$date->format('n') - 1] . ' de ' . $date->format('Y');
    }

    private static function name(User $user): string
    {
        $name = trim((string)$user->first_name . ' ' . (string)$user->last_name);
        return $name !== '' ? $name : '#' . $user->id;
    }
}
