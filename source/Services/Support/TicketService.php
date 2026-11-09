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
        $link = Environments::url('helpdesk', '/helpdesk/tickets?ticket=' . $ticketId);
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
