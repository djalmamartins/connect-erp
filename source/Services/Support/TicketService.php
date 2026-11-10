<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Models\Notification\Notification;
use Source\Models\Notification\NotificationCategory;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Environments;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\Email;

/**
 * Abertura de chamados (ADR 0034): uma porta só para todas as origens.
 *
 * Help Desk (fila interna), ERP (/operacional/chamados), site (widget público), portal dos
 * moradores e API REST (/api/v1/tickets) gravam pelo mesmo serviço em studio_support_tickets,
 * e todo chamado cai na fila do Help Desk com condomínio, unidade, solicitante e origem.
 *
 * Regras:
 * - assunto (4 a 180) e descrição (10+) obrigatórios; área, prioridade e origem por lista fechada;
 * - condomínio dentro do escopo de quem abre (CondominiumScope), quando há usuário;
 * - unidade só junto com o condomínio e pertencendo a ele;
 * - solicitante: um usuário do sistema ou nome + e-mail (morador, visitante do site);
 * - prazo (SLA) pela prioridade; protocolo CH + aammdd + 4 hex.
 */
final class TicketService
{
    public const ORIGINS = ['helpdesk', 'erp', 'site', 'residents', 'api'];
    public const AREAS = ['general', 'technical', 'financial'];
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];
    public const STATUSES = ['open', 'in_progress', 'waiting_customer', 'resolved', 'closed'];

    public const ORIGIN_LABELS = ['helpdesk' => 'Help Desk', 'erp' => 'ERP', 'site' => 'Site', 'residents' => 'Portal dos moradores', 'api' => 'API'];
    public const AREA_LABELS = ['general' => 'Geral', 'technical' => 'Técnico', 'financial' => 'Financeiro'];
    public const PRIORITY_LABELS = ['low' => 'Baixa', 'medium' => 'Média', 'high' => 'Alta', 'urgent' => 'Urgente'];
    public const STATUS_LABELS = ['open' => 'Aberto', 'in_progress' => 'Em atendimento', 'waiting_customer' => 'Aguardando cliente', 'resolved' => 'Resolvido', 'closed' => 'Fechado'];

    private const ADMIN_LEVEL = 5;

    private ?PDO $pdo;
    private ?CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo;
        $this->scope = $scope;
    }

    public static function slaHours(string $priority): int
    {
        return ['urgent' => 24, 'high' => 48, 'medium' => 72, 'low' => 120][$priority] ?? 72;
    }

    /**
     * Valida e normaliza a entrada. Não consulta o banco.
     *
     * @return array<string, mixed>
     * @throws TicketError
     */
    public static function normalize(array $input, string $origin): array
    {
        if (!in_array($origin, self::ORIGINS, true)) {
            throw new TicketError('origin', 'Origem do chamado inválida.');
        }
        $text = static fn (string $key, int $max): string => mb_substr(trim(strip_tags((string)($input[$key] ?? ''))), 0, $max);
        $id = static fn (string $key): ?int => ($value = filter_var($input[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])) === false ? null : (int)$value;

        $data = [
            'subject' => $text('subject', 180),
            'message' => trim(strip_tags((string)($input['message'] ?? ''))),
            'area' => in_array($input['area'] ?? '', self::AREAS, true) ? $input['area'] : 'general',
            'priority' => in_array($input['priority'] ?? '', self::PRIORITIES, true) ? $input['priority'] : 'medium',
            'origin' => $origin,
            'category' => $text('category', 100) ?: null,
            'condominium_id' => $id('condominium_id'),
            'unit_id' => $id('unit_id'),
            'requester_id' => $id('requester_id'),
            'requester_name' => $text('requester_name', 160) ?: null,
            'requester_email' => mb_strtolower($text('requester_email', 190)) ?: null,
            'requester_phone' => preg_replace('/[^0-9+() -]/', '', $text('requester_phone', 30)) ?: null,
            'assigned_to' => $id('assigned_to'),
        ];
        if (mb_strlen($data['subject']) < 4) {
            throw new TicketError('subject', 'Informe um assunto com pelo menos 4 caracteres.');
        }
        if (mb_strlen($data['message']) < 10) {
            throw new TicketError('message', 'Descreva o chamado com pelo menos 10 caracteres.');
        }
        if ($data['unit_id'] !== null && $data['condominium_id'] === null) {
            throw new TicketError('condominium_id', 'Escolha o condomínio da unidade.');
        }
        if ($data['requester_email'] !== null && !filter_var($data['requester_email'], FILTER_VALIDATE_EMAIL)) {
            throw new TicketError('requester_email', 'Informe um e-mail válido para o solicitante.');
        }
        if ($data['requester_name'] !== null && mb_strlen($data['requester_name']) < 2) {
            throw new TicketError('requester_name', 'Informe o nome do solicitante.');
        }
        return $data;
    }

    /**
     * Abre o chamado. $actor é quem registra (null no site e no portal, que já chegam com o
     * solicitante e, no portal, com condomínio e unidade definidos pelo servidor).
     * $afterInsert roda na mesma transação (ex.: anexos do widget).
     *
     * @return array{id:int,protocol:string,due_at:string,access_token:?string}
     * @throws TicketError
     */
    public function open(array $input, string $origin, ?User $actor = null, ?callable $afterInsert = null): array
    {
        $data = self::normalize($input, $origin);
        $pdo = $this->pdo();

        if ($data['condominium_id'] !== null) {
            if ($actor !== null && !$this->scope()->canAccess($actor, $data['condominium_id'])) {
                throw new TicketError('condominium_id', 'Condomínio indisponível para a sua conta.', 403);
            }
            $condominium = $pdo->prepare("SELECT id FROM condominiums WHERE id = :id AND status = 'active'");
            $condominium->execute(['id' => $data['condominium_id']]);
            if (!$condominium->fetchColumn()) {
                throw new TicketError('condominium_id', 'Condomínio não encontrado.');
            }
        }
        if ($data['unit_id'] !== null) {
            $unit = $pdo->prepare('SELECT id FROM erp_units WHERE id = :id AND condominium_id = :condominium AND deleted_at IS NULL');
            $unit->execute(['id' => $data['unit_id'], 'condominium' => $data['condominium_id']]);
            if (!$unit->fetchColumn()) {
                throw new TicketError('unit_id', 'A unidade não pertence ao condomínio escolhido.');
            }
        }

        // Solicitante: usuário escolhido, ou nome + e-mail informados, ou quem registra.
        if ($data['requester_id'] !== null) {
            if (!(new User())->findById($data['requester_id'])) {
                throw new TicketError('requester_id', 'Selecione um solicitante válido.');
            }
        } elseif ($data['requester_name'] === null && $actor !== null) {
            $data['requester_id'] = (int)$actor->id;
        }
        if ($data['requester_id'] === null && ($data['requester_name'] === null || $data['requester_email'] === null)) {
            throw new TicketError('requester_name', 'Informe nome e e-mail do solicitante.');
        }
        if ($data['assigned_to'] !== null && !(new User())->findById($data['assigned_to'])) {
            $data['assigned_to'] = null;
        }

        // A coluna protocol tem 12 caracteres: CH + aammdd + 4 hex.
        $protocol = 'CH' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
        $dueAt = date('Y-m-d H:i:s', strtotime('+' . self::slaHours($data['priority']) . ' hours'));
        // Solicitante de fora (sem conta) acompanha o chamado pelo código de acesso.
        $accessToken = $data['requester_id'] === null ? hash('sha256', random_bytes(32)) : null;

        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO studio_support_tickets
                (protocol, subject, message, area, origin, condominium_id, unit_id, category, priority, requester_id, requester_name, requester_email, requester_phone, access_token, assigned_to, created_by, due_at)
                VALUES (:protocol, :subject, :message, :area, :origin, :condominium, :unit, :category, :priority, :requester, :name, :email, :phone, :token, :assigned, :creator, :due)');
            $stmt->execute([
                'protocol' => $protocol, 'subject' => $data['subject'], 'message' => $data['message'],
                'area' => $data['area'], 'origin' => $data['origin'], 'condominium' => $data['condominium_id'],
                'unit' => $data['unit_id'], 'category' => $data['category'], 'priority' => $data['priority'],
                'requester' => $data['requester_id'], 'name' => $data['requester_name'], 'email' => $data['requester_email'],
                'phone' => $data['requester_phone'], 'token' => $accessToken, 'assigned' => $data['assigned_to'],
                'creator' => $actor !== null ? (int)$actor->id : null, 'due' => $dueAt,
            ]);
            $ticketId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, new_value) VALUES (?, ?, ?, ?)')
                ->execute([$ticketId, $actor !== null ? (int)$actor->id : null, 'origin', $data['origin']]);
            if ($afterInsert !== null) {
                $afterInsert($pdo, $ticketId);
            }
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }

        Audit::record('create', 'studio_support_tickets', $ticketId, [], [
            'protocol' => $protocol, 'origin' => $data['origin'], 'condominium_id' => $data['condominium_id'],
            'unit_id' => $data['unit_id'], 'priority' => $data['priority'], 'due_at' => $dueAt,
        ]);
        try {
            $this->notifyOpened($ticketId, $protocol, $data, $dueAt);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_notification_failed', 'ticket_id' => $ticketId]);
        }
        return ['id' => $ticketId, 'protocol' => $protocol, 'due_at' => $dueAt, 'access_token' => $accessToken];
    }

    /**
     * Chamados dos condomínios do escopo do usuário (ERP e API), mais os que ele mesmo abriu.
     *
     * @param array{condominium_id?:int,status?:string,q?:string} $filters
     * @return list<array<string, mixed>>
     */
    public function listFor(User $user, array $filters = [], int $limit = 100): array
    {
        $ids = $this->scope()->allowedIds($user);
        $terms = [];
        $params = ['me' => (int)$user->id, 'me2' => (int)$user->id];
        $visible = ['t.created_by = :me', 't.requester_id = :me2'];
        if ($ids !== []) {
            $in = [];
            foreach ($ids as $index => $id) {
                $in[] = ':c' . $index;
                $params['c' . $index] = $id;
            }
            $visible[] = 't.condominium_id IN (' . implode(',', $in) . ')';
        }
        $terms[] = '(' . implode(' OR ', $visible) . ')';
        $condominium = (int)($filters['condominium_id'] ?? 0);
        if ($condominium > 0) {
            $terms[] = 't.condominium_id = :condominium';
            $params['condominium'] = $condominium;
        }
        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $terms[] = 't.status = :status';
            $params['status'] = $filters['status'];
        }
        $search = trim((string)($filters['q'] ?? ''));
        if ($search !== '') {
            $terms[] = '(t.subject LIKE :q OR t.protocol LIKE :q2)';
            $params['q'] = '%' . $search . '%';
            $params['q2'] = '%' . $search . '%';
        }
        $stmt = $this->pdo()->prepare(self::SELECT . ' WHERE ' . implode(' AND ', $terms)
            . ' ORDER BY t.created_at DESC, t.id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute($params);
        return array_map([self::class, 'present'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Um chamado visível para o usuário (mesma regra de listFor), ou null. */
    public function findFor(User $user, int $id): ?array
    {
        $stmt = $this->pdo()->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $mine = (int)($row['created_by'] ?? 0) === (int)$user->id || (int)($row['requester_id'] ?? 0) === (int)$user->id;
        if (!$mine && ($row['condominium_id'] === null || !$this->scope()->canAccess($user, (int)$row['condominium_id']))) {
            return null;
        }
        return self::present($row);
    }

    /**
     * Conversa do chamado (ADR 0142). Para o solicitante e o ERP, só o que é público: as notas
     * internas do Help Desk ficam de fora. Cada mensagem diz se veio da equipe ou do solicitante.
     *
     * @return list<array{id:int,author:string,from_team:bool,message:string,internal:bool,created_at:string,attachments:list<array{name:string,url:string}>}>
     */
    public function conversation(array $ticket, bool $includeInternal = false): array
    {
        $ticketId = (int)$ticket['id'];
        $requesterId = (int)($ticket['requester']['user_id'] ?? $ticket['requester_id'] ?? 0);
        $creator = $this->pdo()->prepare('SELECT created_by FROM studio_support_tickets WHERE id = ?');
        $creator->execute([$ticketId]);
        $creatorId = (int)$creator->fetchColumn();
        $stmt = $this->pdo()->prepare("SELECT m.id, m.user_id, m.message, m.is_internal, m.created_at,
                NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '') AS author
            FROM studio_support_ticket_messages m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = :ticket" . ($includeInternal ? '' : ' AND m.is_internal = 0') . ' ORDER BY m.created_at, m.id');
        $stmt->execute(['ticket' => $ticketId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $files = [];
        if ($rows !== []) {
            $attachments = $this->pdo()->prepare('SELECT message_id, file_path, original_name FROM studio_support_ticket_attachments WHERE ticket_id = :ticket AND message_id IS NOT NULL ORDER BY id');
            $attachments->execute(['ticket' => $ticketId]);
            foreach ($attachments->fetchAll(PDO::FETCH_ASSOC) as $file) {
                $files[(int)$file['message_id']][] = ['name' => (string)$file['original_name'], 'url' => url('/' . ltrim((string)$file['file_path'], '/'))];
            }
        }
        $messages = [];
        foreach ($rows as $row) {
            $authorId = (int)($row['user_id'] ?? 0);
            $messages[] = [
                'id' => (int)$row['id'],
                'author' => (string)($row['author'] ?? 'Equipe Connect'),
                'from_team' => $authorId === 0 || ($authorId !== $requesterId && $authorId !== $creatorId),
                'message' => (string)$row['message'],
                'internal' => (bool)$row['is_internal'],
                'created_at' => (string)$row['created_at'],
                'attachments' => $files[(int)$row['id']] ?? [],
            ];
        }
        return $messages;
    }

    /**
     * Resposta de quem abriu ou pediu o chamado, pelo ERP ou pela API (ADR 0142).
     * Chamado aguardando o cliente volta para atendimento; resolvido ou fechado é reaberto.
     * A equipe (responsável, ou o Help Desk se não houver) é avisada.
     *
     * @return array{message_id:int,status:string}
     * @throws TicketError
     */
    public function replyAsRequester(User $user, int $ticketId, string $message): array
    {
        $ticket = $this->findFor($user, $ticketId);
        if ($ticket === null) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        $message = trim(strip_tags($message));
        if (mb_strlen($message) < 2) {
            throw new TicketError('message', 'Escreva a mensagem antes de enviar.');
        }
        $message = mb_substr($message, 0, 10000);
        $status = match ($ticket['status']) {
            'waiting_customer' => 'in_progress',
            'resolved', 'closed' => 'open',
            default => $ticket['status'],
        };
        $pdo = $this->pdo();
        try {
            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO studio_support_ticket_messages (ticket_id, user_id, message, is_internal) VALUES (?, ?, ?, 0)')
                ->execute([$ticketId, (int)$user->id, $message]);
            $messageId = (int)$pdo->lastInsertId();
            if ($status !== $ticket['status']) {
                $pdo->prepare('UPDATE studio_support_tickets SET status = ?, resolved_at = NULL WHERE id = ?')->execute([$status, $ticketId]);
                $pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, old_value, new_value) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$ticketId, (int)$user->id, 'status', $ticket['status'], $status]);
            }
            $pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, new_value) VALUES (?, ?, ?, ?)')
                ->execute([$ticketId, (int)$user->id, 'requester_reply', 'erp']);
            $pdo->commit();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('reply', 'studio_support_tickets', $ticketId, ['status' => $ticket['status']], ['status' => $status, 'message_id' => $messageId]);
        try {
            $this->notifyTeam($ticket, (int)$user->id, $message, $status !== $ticket['status'] && $status === 'open');
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_notification_failed', 'ticket_id' => $ticketId]);
        }
        return ['message_id' => $messageId, 'status' => $status];
    }

    /**
     * Avisa quem pediu o chamado que o Help Desk respondeu ou mudou a situação (ADR 0142).
     * Quem tem conta recebe a notificação (sino do ERP e do Studio) e o e-mail com o link do chamado
     * no ERP; solicitante sem conta recebe só o e-mail com a resposta. Nunca avisa o próprio autor.
     *
     * @param 'reply'|'status' $kind
     */
    public function notifyRequester(int $ticketId, string $kind, int $authorId, ?string $text = null): void
    {
        $stmt = $this->pdo()->prepare(self::SELECT . ' WHERE t.id = :id');
        $stmt->execute(['id' => $ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return;
        }
        $ticket = self::present($row);
        $protocol = $ticket['protocol'];
        $statusLabel = self::STATUS_LABELS[$ticket['status']] ?? $ticket['status'];
        $title = $kind === 'reply' ? "Resposta no chamado {$protocol}" : "Chamado {$protocol}: {$statusLabel}";
        $summary = $kind === 'reply'
            ? mb_strimwidth(trim(preg_replace('/\s+/', ' ', (string)$text) ?? ''), 0, 160, '…')
            : "A situação do chamado \"{$ticket['subject']}\" mudou para {$statusLabel}.";
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        $category = self::notificationCategory();

        $recipients = array_unique(array_filter([(int)($ticket['requester']['user_id'] ?? 0), (int)($row['created_by'] ?? 0)]));
        foreach ($recipients as $recipientId) {
            if ($recipientId === $authorId) {
                continue;
            }
            $recipient = (new User())->findById($recipientId);
            if (!$recipient) {
                continue;
            }
            $link = self::linkFor($recipient, $ticketId);
            if ($category) {
                $notification = new Notification();
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = 'images/default.svg';
                $notification->title = $title;
                $notification->body = $summary;
                $notification->severity = $ticket['status'] === 'waiting_customer' ? 'warning' : 'info';
                // link é obrigatório no modelo; sem ambiente para a pessoa, o clique leva ao início.
                $notification->link = $link ?? '/';
                $notification->view = 0;
                $notification->save();
            }
            if (is_email((string)$recipient->email)) {
                $body = '<p>Olá, ' . $e((string)$recipient->first_name) . '.</p>'
                    . ($kind === 'reply'
                        ? '<p>A equipe Connect respondeu o chamado <strong>' . $e($protocol) . '</strong> (' . $e($ticket['subject']) . '):</p><blockquote>' . nl2br($e((string)$text)) . '</blockquote>'
                        : '<p>O chamado <strong>' . $e($protocol) . '</strong> (' . $e($ticket['subject']) . ') agora está <strong>' . $e($statusLabel) . '</strong>.</p>')
                    . ($link !== null ? '<p><a href="' . $e($link) . '">Ver o chamado e responder</a></p>' : '');
                (new Email())->bootstrap("[{$protocol}] {$title}", $body, $recipient->email, $recipient->fullName())
                    ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, $recipient->id);
            }
        }
        // Solicitante sem conta (site, morador informado no ERP): só o e-mail, sem link de sistema.
        if ($ticket['requester']['user_id'] === null && is_email((string)$ticket['requester']['email'])) {
            $name = (string)($ticket['requester']['name'] ?? '');
            $body = '<p>Olá' . ($name !== '' ? ', ' . $e($name) : '') . '.</p>'
                . ($kind === 'reply'
                    ? '<p>Temos uma resposta para o seu chamado <strong>' . $e($protocol) . '</strong> (' . $e($ticket['subject']) . '):</p><blockquote>' . nl2br($e((string)$text)) . '</blockquote>'
                    : '<p>O seu chamado <strong>' . $e($protocol) . '</strong> (' . $e($ticket['subject']) . ') agora está <strong>' . $e($statusLabel) . '</strong>.</p>')
                . '<p>Para responder, use este e-mail ou fale com a administradora informando o protocolo.</p>';
            (new Email())->bootstrap("[{$protocol}] {$title}", $body, (string)$ticket['requester']['email'], $name !== '' ? $name : (string)$ticket['requester']['email'])
                ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'));
        }
    }

    /**
     * Onde a pessoa acompanha o chamado: no ERP (Operacional › Chamados) para quem entra no ERP,
     * no Help Desk para quem só atende, ou null.
     */
    public static function linkFor(User $user, int $ticketId): ?string
    {
        if (Access::can('erp.access', $user)) {
            return Environments::url('erp', self::erpPath($ticketId));
        }
        if (Access::can('support.manage', $user)) {
            return Environments::url('helpdesk', self::helpdeskPath($ticketId));
        }
        return null;
    }

    public static function erpPath(int $ticketId): string
    {
        return '/operacional/chamados/' . $ticketId;
    }

    public static function helpdeskPath(int $ticketId): string
    {
        return '/helpdesk/tickets?ticket=' . $ticketId;
    }

    /** Avisa a equipe de uma resposta do solicitante: o responsável, ou o Help Desk inteiro. */
    private function notifyTeam(array $ticket, int $authorId, string $message, bool $reopened): void
    {
        $recipientIds = [];
        if ($ticket['assigned_to'] !== null) {
            $recipientIds[] = (int)$ticket['assigned_to']['id'];
        } else {
            foreach ((new User())->find('level >= :level', 'level=' . self::ADMIN_LEVEL)->fetch(true) ?: [] as $member) {
                $recipientIds[] = (int)$member->id;
            }
        }
        $category = self::notificationCategory();
        $link = Environments::url('helpdesk', self::helpdeskPath((int)$ticket['id']));
        $title = ($reopened ? 'Chamado reaberto ' : 'Nova mensagem no chamado ') . $ticket['protocol'];
        $summary = mb_strimwidth(trim(preg_replace('/\s+/', ' ', $message) ?? ''), 0, 160, '…');
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
        foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
            if ($recipientId === $authorId) {
                continue;
            }
            $recipient = (new User())->findById($recipientId);
            if (!$recipient) {
                continue;
            }
            if ($category) {
                $notification = new Notification();
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = 'images/default.svg';
                $notification->title = $title;
                $notification->body = $summary;
                $notification->severity = $reopened ? 'warning' : 'info';
                $notification->link = $link;
                $notification->view = 0;
                $notification->save();
            }
            if (is_email((string)$recipient->email)) {
                $body = '<p>Olá, ' . $e((string)$recipient->first_name) . '.</p><p>' . $e($title) . ' (' . $e($ticket['subject']) . '):</p><blockquote>' . nl2br($e($message)) . '</blockquote>'
                    . '<p><a href="' . $e($link) . '">Abrir no Help Desk</a></p>';
                (new Email())->bootstrap("[{$ticket['protocol']}] {$title}", $body, $recipient->email, $recipient->fullName())
                    ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, $recipient->id);
            }
        }
    }

    /**
     * Resumo da fila do Help Desk para o painel do Studio (ADR 0153): abertos, vencidos, aguardando
     * o cliente, vindos do ERP nos últimos 7 dias e os 5 abertos mais urgentes.
     *
     * @return array{open:int,overdue:int,waiting:int,erp_week:int,latest:list<array{id:int,protocol:string,subject:string,priority:string,status:string,context:string,due_at:string}>}
     */
    public function queueSummary(): array
    {
        $row = $this->pdo()->query("SELECT
                SUM(status IN ('open','in_progress')) AS open_count,
                SUM(status NOT IN ('resolved','closed') AND due_at < NOW()) AS overdue_count,
                SUM(status = 'waiting_customer') AS waiting_count,
                SUM(origin = 'erp' AND created_at >= (NOW() - INTERVAL 7 DAY)) AS erp_week
            FROM studio_support_tickets")->fetch(PDO::FETCH_ASSOC) ?: [];
        $latest = $this->pdo()->query("SELECT t.id, t.protocol, t.subject, t.priority, t.status, t.due_at, c.name AS condominium_name, u.label AS unit_label
            FROM studio_support_tickets t LEFT JOIN condominiums c ON c.id = t.condominium_id LEFT JOIN erp_units u ON u.id = t.unit_id
            WHERE t.status IN ('open','in_progress','waiting_customer')
            ORDER BY FIELD(t.priority,'urgent','high','medium','low'), t.due_at, t.id LIMIT 5")->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'open' => (int)($row['open_count'] ?? 0),
            'overdue' => (int)($row['overdue_count'] ?? 0),
            'waiting' => (int)($row['waiting_count'] ?? 0),
            'erp_week' => (int)($row['erp_week'] ?? 0),
            'latest' => array_map(static fn (array $item): array => [
                'id' => (int)$item['id'],
                'protocol' => (string)$item['protocol'],
                'subject' => (string)$item['subject'],
                'priority' => (string)$item['priority'],
                'status' => (string)$item['status'],
                'context' => implode(' · ', array_filter([(string)($item['condominium_name'] ?? ''), $item['unit_label'] !== null ? 'Unid. ' . $item['unit_label'] : ''])),
                'due_at' => (string)$item['due_at'],
            ], $latest),
        ];
    }

    /** @return list<array{id:int,label:string}> unidades ativas do condomínio */
    public function units(int $condominiumId): array
    {
        if ($condominiumId <= 0) {
            return [];
        }
        $stmt = $this->pdo()->prepare("SELECT id, label FROM erp_units WHERE condominium_id = :id AND deleted_at IS NULL AND status = 'active' ORDER BY label");
        $stmt->execute(['id' => $condominiumId]);
        return array_map(static fn (array $row): array => ['id' => (int)$row['id'], 'label' => (string)$row['label']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Unidades de vários condomínios, agrupadas por condomínio (seleção dependente no formulário).
     *
     * @param list<int> $condominiumIds
     * @return array<int, list<array{id:int,label:string}>>
     */
    public function unitsByCondominium(array $condominiumIds): array
    {
        $condominiumIds = array_values(array_filter(array_map('intval', $condominiumIds)));
        if ($condominiumIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($condominiumIds), '?'));
        $stmt = $this->pdo()->prepare("SELECT id, condominium_id, label FROM erp_units WHERE condominium_id IN ({$placeholders}) AND deleted_at IS NULL AND status = 'active' ORDER BY label");
        $stmt->execute($condominiumIds);
        $units = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $units[(int)$row['condominium_id']][] = ['id' => (int)$row['id'], 'label' => (string)$row['label']];
        }
        return $units;
    }

    private const SELECT = "SELECT t.id, t.protocol, t.subject, t.message, t.area, t.origin, t.category, t.priority, t.status,
            t.condominium_id, c.name AS condominium_name, t.unit_id, u.label AS unit_label,
            t.requester_id, COALESCE(NULLIF(TRIM(CONCAT(r.first_name, ' ', r.last_name)), ''), t.requester_name) AS requester_name,
            COALESCE(r.email, t.requester_email) AS requester_email, t.requester_phone,
            t.created_by, t.assigned_to, NULLIF(TRIM(CONCAT(a.first_name, ' ', a.last_name)), '') AS assigned_name,
            t.due_at, t.resolved_at, t.created_at
        FROM studio_support_tickets t
        LEFT JOIN condominiums c ON c.id = t.condominium_id
        LEFT JOIN erp_units u ON u.id = t.unit_id
        LEFT JOIN users r ON r.id = t.requester_id
        LEFT JOIN users a ON a.id = t.assigned_to";

    /** Linha do banco para a forma pública (telas do ERP e API). */
    public static function present(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'protocol' => (string)$row['protocol'],
            'subject' => (string)$row['subject'],
            'message' => (string)($row['message'] ?? ''),
            'area' => (string)$row['area'],
            'origin' => (string)($row['origin'] ?? 'helpdesk'),
            'category' => $row['category'] !== null ? (string)$row['category'] : null,
            'priority' => (string)$row['priority'],
            'status' => (string)$row['status'],
            'condominium' => $row['condominium_id'] !== null ? ['id' => (int)$row['condominium_id'], 'name' => (string)($row['condominium_name'] ?? '')] : null,
            'unit' => $row['unit_id'] !== null ? ['id' => (int)$row['unit_id'], 'label' => (string)($row['unit_label'] ?? '')] : null,
            'requester' => [
                'user_id' => $row['requester_id'] !== null ? (int)$row['requester_id'] : null,
                'name' => $row['requester_name'] !== null ? (string)$row['requester_name'] : null,
                'email' => $row['requester_email'] !== null ? (string)$row['requester_email'] : null,
                'phone' => $row['requester_phone'] !== null ? (string)$row['requester_phone'] : null,
            ],
            'assigned_to' => $row['assigned_to'] !== null ? ['id' => (int)$row['assigned_to'], 'name' => (string)($row['assigned_name'] ?? '')] : null,
            'due_at' => (string)$row['due_at'],
            'resolved_at' => $row['resolved_at'] !== null ? (string)$row['resolved_at'] : null,
            'created_at' => (string)$row['created_at'],
        ];
    }

    /** Categoria "studio" das notificações internas (criada na primeira vez). */
    public static function notificationCategory(): ?NotificationCategory
    {
        $category = (new NotificationCategory())->findByUri('studio');
        if ($category) {
            return $category;
        }
        $category = new NotificationCategory();
        $category->title = 'Studio';
        $category->uri = 'studio';
        $category->description = 'Avisos operacionais do Studio';
        $category->type = 'system';
        return $category->save() ? $category : null;
    }

    /** Avisa a equipe do Help Desk (níveis 5+), o responsável e o solicitante com conta. */
    private function notifyOpened(int $ticketId, string $protocol, array $data, string $dueAt): void
    {
        $recipientIds = [$data['requester_id'], $data['assigned_to']];
        foreach ((new User())->find('level >= :level', 'level=' . self::ADMIN_LEVEL)->fetch(true) ?: [] as $member) {
            $recipientIds[] = (int)$member->id;
        }
        $category = self::notificationCategory();
        $priorityLabel = self::PRIORITY_LABELS[$data['priority']] ?? 'Média';
        $link = Environments::url('helpdesk', self::helpdeskPath($ticketId));
        $where = $this->contextLine($data);
        foreach (array_unique(array_filter($recipientIds)) as $recipientId) {
            $recipient = (new User())->findById((int)$recipientId);
            if (!$recipient) {
                continue;
            }
            if ($category) {
                $notification = new Notification();
                $notification->users_id = $recipient->id;
                $notification->category = $category->id;
                $notification->image = 'images/default.svg';
                $notification->title = "Novo chamado {$protocol}";
                $notification->body = "{$priorityLabel}: {$data['subject']}" . ($where !== '' ? " ({$where})" : '') . '. SLA até ' . date_fmt($dueAt, 'd/m H:i');
                $notification->severity = in_array($data['priority'], ['urgent', 'high'], true) ? 'warning' : 'info';
                $notification->link = $link;
                $notification->view = 0;
                $notification->save();
            }
            if (is_email((string)$recipient->email)) {
                $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
                $body = '<p>Olá, ' . $e((string)$recipient->first_name) . '.</p><p>Foi aberto o chamado <strong>' . $e($protocol) . '</strong>: ' . $e($data['subject']) . '.</p>'
                    . ($where !== '' ? '<p>' . $e($where) . '</p>' : '')
                    . '<p>Origem: <strong>' . $e(self::ORIGIN_LABELS[$data['origin']] ?? $data['origin']) . '</strong>. Prioridade: <strong>' . $e($priorityLabel) . '</strong>. Prazo de atendimento: <strong>' . date_fmt($dueAt, 'd/m/Y H:i') . '</strong>.</p>'
                    . '<p><a href="' . $e($link) . '">Abrir chamado no Help Desk</a></p>';
                (new Email())->bootstrap("[{$protocol}] Novo chamado: {$data['subject']}", $body, $recipient->email, $recipient->fullName())
                    ->queue(CONF_MAIL_SENDER['address'], CONF_MAIL_SENDER['name'], date('Y-m-d H:i:s'), null, $recipient->id);
            }
        }
    }

    /** "Condomínio X · Unidade Y" para avisos. */
    private function contextLine(array $data): string
    {
        $parts = [];
        if ($data['condominium_id'] !== null) {
            $stmt = $this->pdo()->prepare('SELECT name FROM condominiums WHERE id = ?');
            $stmt->execute([$data['condominium_id']]);
            $name = $stmt->fetchColumn();
            if ($name !== false) {
                $parts[] = 'Condomínio ' . $name;
            }
        }
        if ($data['unit_id'] !== null) {
            $stmt = $this->pdo()->prepare('SELECT label FROM erp_units WHERE id = ?');
            $stmt->execute([$data['unit_id']]);
            $label = $stmt->fetchColumn();
            if ($label !== false) {
                $parts[] = 'Unidade ' . $label;
            }
        }
        return implode(' · ', $parts);
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }

    private function scope(): CondominiumScope
    {
        return $this->scope ??= new CondominiumScope($this->pdo());
    }
}
