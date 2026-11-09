<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use ReflectionClass;
use Source\Controllers\Studio\Studio;
use Source\Controllers\Web\Support\Support;
use Source\Models\Notification\NotificationMessage;
use Source\Models\User;
use Source\Support\Communication;

final class ServiceDeskTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Os controllers do Studio checam studio.access e outras permissões cadastradas.
        if (isset($this->pdo)) {
            $this->registerMigrationPermissions();
        }
    }

    public function testPublicWidgetCreatesAndFindsTicketByProtocol(): void
    {
        $_SESSION['csrf_token'] = 'widget-token';
        $support = new Support();
        ob_start();
        $support->widgetCreate([
            'csrf' => 'widget-token', 'name' => 'Cliente Externo', 'email' => 'cliente@example.com',
            'subject' => 'Não consigo acessar o portal', 'message' => 'O portal informa que minha senha está incorreta.',
        ]);
        $created = json_decode((string)ob_get_clean(), true);
        self::assertTrue($created['success'] ?? false);
        self::assertMatchesRegularExpression('/^CH\d{6}[A-F0-9]{4}$/', $created['protocol']);

        $ticket = $this->pdo->query("SELECT * FROM studio_support_tickets WHERE protocol=" . $this->pdo->quote($created['protocol']))->fetch();
        self::assertSame('Cliente Externo', $ticket->requester_name);
        self::assertSame('cliente@example.com', $ticket->requester_email);
        self::assertNull($ticket->created_by);

        ob_start();
        $support->widgetLookup(['csrf' => 'widget-token', 'protocol' => $created['protocol'], 'email' => 'cliente@example.com', 'token' => $created['token']]);
        $found = json_decode((string)ob_get_clean(), true);
        self::assertTrue($found['success'] ?? false);
        self::assertSame($created['protocol'], $found['ticket']['protocol'] ?? null);
    }

    public function testAgendaControllerCreatesAndUpdatesAnEvent(): void
    {
        $userId = $this->createUser(['level' => 10]);
        $_SESSION['authUser'] = $userId;
        $_SESSION['csrf_token'] = 'agenda-token';
        $studio = new Studio();

        ob_start();
        $studio->agenda([
            'csrf' => 'agenda-token', 'action' => 'save', 'title' => 'Reunião de operação',
            'description' => 'Revisar chamados pendentes.', 'starts_at' => '2026-09-03T10:00',
            'ends_at' => '2026-09-03T11:00', 'type' => 'meeting', 'status' => 'scheduled',
            'assigned_to' => (string)$userId,
        ]);
        $response = json_decode((string)ob_get_clean(), true);
        self::assertStringContainsString('/helpdesk/agenda', $response['redirect'] ?? '');

        $event = $this->pdo->query('SELECT * FROM studio_calendar_events LIMIT 1')->fetch();
        self::assertNotFalse($event);
        ob_start();
        $studio->agenda([
            'csrf' => 'agenda-token', 'action' => 'save', 'event_id' => (string)$event->id,
            'title' => 'Reunião de operação', 'description' => 'Concluída.',
            'starts_at' => '2026-09-03T10:00', 'ends_at' => '2026-09-03T11:00',
            'type' => 'meeting', 'status' => 'completed', 'assigned_to' => (string)$userId,
        ]);
        ob_end_clean();
        self::assertSame('completed', $this->pdo->query("SELECT status FROM studio_calendar_events WHERE id={$event->id}")->fetchColumn());
    }

    public function testTicketsControllerCreatesTicketAndQueuesNotifications(): void
    {
        $userId = $this->createUser(['level' => 10, 'email' => 'operator@example.com']);
        $_SESSION['authUser'] = $userId;
        $_SESSION['csrf_token'] = 'ticket-token';

        ob_start();
        (new Studio())->tickets([
            'csrf' => 'ticket-token', 'action' => 'create', 'subject' => 'Erro no cadastro de moradores',
            'message' => 'O formulário não conclui o cadastro informado.', 'area' => 'technical',
            'priority' => 'high', 'requester_id' => (string)$userId, 'assigned_to' => (string)$userId,
        ]);
        $response = json_decode((string)ob_get_clean(), true);

        self::assertStringContainsString('/helpdesk/tickets', $response['redirect'] ?? '');
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM studio_support_tickets')->fetchColumn());
        self::assertSame('high', $this->pdo->query('SELECT priority FROM studio_support_tickets LIMIT 1')->fetchColumn());
        self::assertGreaterThanOrEqual(1, (int)$this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        self::assertGreaterThanOrEqual(1, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn());
    }

    public function testCommunicationCreatesMissingCategoryAndDeliversBothChannels(): void
    {
        $recipientId = $this->createUser(['email' => 'notification@example.com']);
        $this->pdo->exec("DELETE FROM notifications_categories WHERE uri='studio'");

        $message = new NotificationMessage();
        $message->title = 'Aviso operacional';
        $message->body = 'Mensagem de validação dos canais.';
        $message->audience = 'user';
        $message->target_user_id = $recipientId;
        $message->severity = 'info';
        $message->delivery_channels = 'both';
        $message->status = 'sent';
        self::assertNotFalse($message->save());

        (new Communication())->deliver($message);

        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications_categories WHERE uri='studio'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE users_id={$recipientId}")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM mail_queue WHERE users_id={$recipientId}")->fetchColumn());
    }

    public function testTicketStoresUrgentSlaAndInteractionHistory(): void
    {
        $userId = $this->createUser(['level' => 10]);
        $dueAt = date('Y-m-d H:i:s', time() + 86400);
        $this->pdo->prepare('INSERT INTO studio_support_tickets(protocol,subject,message,area,priority,status,requester_id,assigned_to,created_by,due_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute(['CH240101ABC1','Falha crítica','O serviço precisa de atendimento imediato.','technical','urgent','open',$userId,$userId,$userId,$dueAt]);
        $ticketId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO studio_support_ticket_messages(ticket_id,user_id,message,is_internal) VALUES (?,?,?,?)')
            ->execute([$ticketId,$userId,'Equipe acionada.',1]);

        $ticket = $this->pdo->query("SELECT * FROM studio_support_tickets WHERE id={$ticketId}")->fetch();
        self::assertSame('urgent', $ticket->priority);
        self::assertSame($dueAt, $ticket->due_at);
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_messages WHERE ticket_id={$ticketId}")->fetchColumn());
    }

    public function testTicketProtocolFitsTheDatabaseColumn(): void
    {
        $protocol = 'CH' . date('ymd') . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4));
        self::assertSame(12, strlen($protocol));
        $userId = $this->createUser();
        $this->pdo->prepare('INSERT INTO studio_support_tickets(protocol,subject,message,area,priority,status,requester_id,created_by,due_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$protocol, 'Validação de protocolo', 'O protocolo deve caber na coluna sem truncamento.', 'general', 'medium', 'open', $userId, $userId, date('Y-m-d H:i:s', time() + 72 * 3600)]);
        self::assertSame($protocol, $this->pdo->query('SELECT protocol FROM studio_support_tickets LIMIT 1')->fetchColumn());
    }

    public function testCalendarPersistsAssignedSupportEvent(): void
    {
        $userId = $this->createUser();
        $this->pdo->prepare('INSERT INTO studio_calendar_events(title,starts_at,type,assigned_to,created_by) VALUES (?,?,?,?,?)')
            ->execute(['Retorno de atendimento',date('Y-m-d 10:00:00'),'support',$userId,$userId]);
        $event = $this->pdo->query('SELECT * FROM studio_calendar_events ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('support', $event->type);
        self::assertSame($userId, (int)$event->assigned_to);
    }

    public function testOpeningTicketQueuesEmailAndCentralNotificationForAdminAndDeveloper(): void
    {
        $requesterId = $this->createUser(['email' => 'requester@example.com']);
        $adminId = $this->createUser(['email' => 'admin@example.com', 'level' => 5]);
        $developerId = $this->createUser(['email' => 'developer@example.com', 'level' => 10]);
        $dueAt = date('Y-m-d H:i:s', time() + 86400);

        $this->pdo->prepare('INSERT INTO studio_support_tickets(protocol,subject,message,area,priority,status,requester_id,created_by,due_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute(['CH240101ABC2', 'Teste de entrega', 'Teste real de notificação e fila de e-mail.', 'technical', 'urgent', 'open', $requesterId, $requesterId, $dueAt]);
        $ticketId = (int)$this->pdo->lastInsertId();

        $reflection = new ReflectionClass(Studio::class);
        $studio = $reflection->newInstanceWithoutConstructor();
        $notify = $reflection->getMethod('notifyTicketOpened');
        $notify->invoke($studio, $ticketId, 'CH240101ABC2', 'Teste de entrega', 'urgent', $dueAt, null, $requesterId);

        $recipients = $this->pdo->query('SELECT users_id FROM mail_queue ORDER BY users_id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([$requesterId, $adminId, $developerId], array_map('intval', $recipients));
        self::assertSame(3, (int)$this->pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn());
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title LIKE 'Novo chamado%'")->fetchColumn());
    }

    public function testAgendaEventQueuesEmailAndCentralNotificationForAdminAndDeveloper(): void
    {
        $creatorId = $this->createUser(['email' => 'creator@example.com']);
        $adminId = $this->createUser(['email' => 'agenda-admin@example.com', 'level' => 5]);
        $developerId = $this->createUser(['email' => 'agenda-developer@example.com', 'level' => 10]);
        $startsAt = date('Y-m-d 10:00:00', strtotime('+1 day'));
        $this->pdo->prepare('INSERT INTO studio_calendar_events(title,starts_at,type,created_by) VALUES (?,?,?,?)')
            ->execute(['Teste de agenda', $startsAt, 'support', $creatorId]);
        $eventId = (int)$this->pdo->lastInsertId();

        $reflection = new ReflectionClass(Studio::class);
        $studio = $reflection->newInstanceWithoutConstructor();
        $notify = $reflection->getMethod('notifyAgendaEvent');
        $notify->invoke($studio, $eventId, 'Teste de agenda', $startsAt, 'support', null, (new User())->findById($creatorId));

        $recipients = $this->pdo->query('SELECT users_id FROM mail_queue ORDER BY users_id')->fetchAll(\PDO::FETCH_COLUMN);
        self::assertSame([$creatorId, $adminId, $developerId], array_map('intval', $recipients));
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title='Novo compromisso na agenda'")->fetchColumn());
    }

    public function testTicketCanBeResolvedReopenedAndKeepsChangeHistory(): void
    {
        $userId = $this->createUser(['level' => 10]);
        $_SESSION['authUser'] = $userId;
        $_SESSION['csrf_token'] = 'transition-token';
        $this->pdo->prepare('INSERT INTO studio_support_tickets(protocol,subject,message,priority,status,requester_id,created_by,due_at) VALUES (?,?,?,?,?,?,?,?)')->execute(['CH260830T001','Fluxo do chamado','Validar resolução e reabertura.','medium','open',$userId,$userId,date('Y-m-d H:i:s',time()+3600)]);
        $ticketId=(int)$this->pdo->lastInsertId();

        ob_start();
        (new Studio())->tickets(['csrf'=>'transition-token','action'=>'update','ticket_id'=>(string)$ticketId,'status'=>'resolved','priority'=>'high','assigned_to'=>(string)$userId,'team'=>'Suporte','category'=>'Acesso','tags'=>'login, urgente']);
        ob_end_clean();
        $resolved=$this->pdo->query("SELECT * FROM studio_support_tickets WHERE id={$ticketId}")->fetch();
        self::assertSame('resolved',$resolved->status);
        self::assertNotNull($resolved->resolved_at);
        self::assertSame('Suporte',$resolved->team);

        ob_start();
        (new Studio())->tickets(['csrf'=>'transition-token','action'=>'update','ticket_id'=>(string)$ticketId,'status'=>'open','priority'=>'high','assigned_to'=>(string)$userId,'team'=>'Suporte','category'=>'Acesso','tags'=>'login, urgente']);
        ob_end_clean();
        self::assertSame('open',$this->pdo->query("SELECT status FROM studio_support_tickets WHERE id={$ticketId}")->fetchColumn());
        self::assertNull($this->pdo->query("SELECT resolved_at FROM studio_support_tickets WHERE id={$ticketId}")->fetchColumn());
        self::assertGreaterThanOrEqual(2,(int)$this->pdo->query("SELECT COUNT(*) FROM studio_support_ticket_events WHERE ticket_id={$ticketId}")->fetchColumn());
        ob_start();
        (new Studio())->tickets(['csrf'=>'transition-token','action'=>'bulk','ticket_ids'=>[$ticketId],'status'=>'closed']);
        $bulk=json_decode((string)ob_get_clean(),true);
        self::assertSame(1,$bulk['affected']);
        self::assertSame('closed',$this->pdo->query("SELECT status FROM studio_support_tickets WHERE id={$ticketId}")->fetchColumn());
    }

    public function testLogActionsResolveIgnoreReopenDeleteAndBatch(): void
    {
        $userId=$this->createUser(['level'=>10]);
        $_SESSION['authUser']=$userId;
        $_SESSION['csrf_token']='log-token';
        $insert=$this->pdo->prepare("INSERT INTO app_log(level,channel,msg,status) VALUES('error','test',?,'open')");
        $insert->execute(['Primeiro']);$first=(int)$this->pdo->lastInsertId();
        $insert->execute(['Segundo']);$second=(int)$this->pdo->lastInsertId();
        $studio=new Studio();
        ob_start();$studio->systemLogAction(['csrf'=>'log-token','id'=>(string)$first,'action'=>'resolve']);$response=json_decode((string)ob_get_clean(),true);
        self::assertSame(1,$response['affected']);
        self::assertSame('resolved',$this->pdo->query("SELECT status FROM app_log WHERE id={$first}")->fetchColumn());
        ob_start();$studio->systemLogAction(['csrf'=>'log-token','id'=>'0','log_ids'=>[$first,$second],'action'=>'ignore']);ob_end_clean();
        self::assertSame(2,(int)$this->pdo->query("SELECT COUNT(*) FROM app_log WHERE status='ignored'")->fetchColumn());
        ob_start();$studio->systemLogAction(['csrf'=>'log-token','id'=>(string)$first,'action'=>'reopen']);ob_end_clean();
        self::assertSame('open',$this->pdo->query("SELECT status FROM app_log WHERE id={$first}")->fetchColumn());
        ob_start();$studio->systemLogAction(['csrf'=>'log-token','id'=>(string)$second,'action'=>'delete']);ob_end_clean();
        self::assertSame(0,(int)$this->pdo->query("SELECT COUNT(*) FROM app_log WHERE id={$second}")->fetchColumn());
    }

    public function testHelpDeskTemplateAndDedicatedTimeArePersisted(): void
    {
        $userId=$this->createUser(['level'=>10]);$_SESSION['authUser']=$userId;$_SESSION['csrf_token']='desk-token';$studio=new Studio();
        ob_start();$studio->tickets(['csrf'=>'desk-token','action'=>'template_save','title'=>'Em análise','body'=>'Estamos analisando sua solicitação.']);ob_end_clean();
        self::assertSame('Em análise',$this->pdo->query('SELECT title FROM studio_support_templates LIMIT 1')->fetchColumn());
        $this->pdo->prepare('INSERT INTO studio_support_tickets(protocol,subject,message,status,requester_id,created_by,due_at) VALUES (?,?,?,?,?,?,?)')->execute(['CH260830T002','Tempo dedicado','Chamado para validar tempo.','open',$userId,$userId,date('Y-m-d H:i:s',time()+3600)]);$ticketId=(int)$this->pdo->lastInsertId();
        ob_start();$studio->tickets(['csrf'=>'desk-token','action'=>'reply','ticket_id'=>(string)$ticketId,'message'=>'Atendimento executado.','time_spent'=>'125','resolve_after'=>'1']);ob_end_clean();
        $ticket=$this->pdo->query("SELECT status,work_seconds,first_response_at FROM studio_support_tickets WHERE id={$ticketId}")->fetch();
        self::assertSame('resolved',$ticket->status);self::assertSame(125,(int)$ticket->work_seconds);self::assertNotNull($ticket->first_response_at);
    }
}
