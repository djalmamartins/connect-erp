<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\Erp\Occurrences;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\Upload;

/**
 * Atendimento do chamado (ADR 0307): as ações de quem atende, iguais no Help Desk (Studio) e em Operacional ›
 * Chamados do ERP. Triagem (situação, prioridade, responsável, equipe, categoria, tags), resposta ao cliente ou
 * nota interna com o tempo dedicado (cronômetro), anexos e "resolver após enviar", situação em lote, o histórico
 * de alterações e os números da fila (por situação, SLA vencido, vence hoje, sem responsável).
 *
 * Cada mudança grava o evento do chamado (studio_support_ticket_events), avisa quem pediu quando a equipe responde
 * ou encerra (TicketService::notifyRequester, ADR 0142) e leva a situação à ocorrência ligada (ADR 0305).
 * Quem chama confere a permissão (support.manage) e, no ERP, o escopo do condomínio.
 */
final class TicketDesk
{
    public const FIELDS = ['status', 'priority', 'assigned_to', 'team', 'category', 'tags'];
    public const EVENT_LABELS = ['status' => 'Situação', 'priority' => 'Prioridade', 'assigned_to' => 'Responsável', 'team' => 'Equipe',
        'category' => 'Categoria', 'tags' => 'Tags', 'origin' => 'Aberto por', 'requester_reply' => 'Resposta de quem pediu',
        'area' => 'Área'];
    private const FINAL = ['resolved', 'closed'];

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * Triagem. $input pode trazer só parte dos campos: o que não vier fica como está.
     *
     * @return array{changed:list<string>, status:string}
     * @throws TicketError
     */
    public function update(User $agent, int $ticketId, array $input): array
    {
        $current = $this->pdo->prepare('SELECT status, priority, assigned_to, team, category, tags FROM studio_support_tickets WHERE id = ?');
        $current->execute([$ticketId]);
        $before = $current->fetch(PDO::FETCH_ASSOC);
        if (!is_array($before)) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        $text = static fn (string $key, int $max): ?string => array_key_exists($key, $input)
            ? (mb_substr(trim(strip_tags((string)$input[$key])), 0, $max) ?: null) : ($before[$key] !== null ? (string)$before[$key] : null);
        $after = [
            'status' => in_array($input['status'] ?? null, TicketService::STATUSES, true) ? (string)$input['status'] : (string)$before['status'],
            'priority' => in_array($input['priority'] ?? null, TicketService::PRIORITIES, true) ? (string)$input['priority'] : (string)$before['priority'],
            'assigned_to' => array_key_exists('assigned_to', $input) ? $this->userId($input['assigned_to']) : ($before['assigned_to'] !== null ? (int)$before['assigned_to'] : null),
            'team' => $text('team', 100),
            'category' => $text('category', 100),
            'tags' => $text('tags', 500),
        ];
        $changed = array_values(array_filter(self::FIELDS, static fn (string $f): bool => (string)($before[$f] ?? '') !== (string)($after[$f] ?? '')));
        if ($changed === []) {
            return ['changed' => [], 'status' => $after['status']];
        }
        $this->pdo->beginTransaction();
        try {
            $final = in_array($after['status'], self::FINAL, true);
            $this->pdo->prepare('UPDATE studio_support_tickets SET status = :status, priority = :priority, assigned_to = :assigned, team = :team, category = :category,
                tags = :tags, resolved_at = ' . ($final ? 'COALESCE(resolved_at, NOW())' : 'NULL') . ' WHERE id = :id')->execute([
                'status' => $after['status'], 'priority' => $after['priority'], 'assigned' => $after['assigned_to'], 'team' => $after['team'],
                'category' => $after['category'], 'tags' => $after['tags'], 'id' => $ticketId,
            ]);
            foreach ($changed as $field) {
                $this->event($ticketId, (int)$agent->id, $field, $before[$field] !== null ? (string)$before[$field] : null, $after[$field] !== null ? (string)$after[$field] : null);
            }
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('update', 'studio_support_tickets', $ticketId, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        if (in_array('status', $changed, true)) {
            (new Occurrences($this->pdo))->followTickets([$ticketId], (int)$agent->id);
            if (in_array($after['status'], ['waiting_customer', 'resolved', 'closed'], true)) {
                $this->notify($ticketId, 'status', (int)$agent->id);
            }
        }
        return ['changed' => $changed, 'status' => $after['status']];
    }

    /**
     * Resposta ao cliente ou nota interna, com o tempo dedicado (segundos do cronômetro, até 24 h) e, se pedido,
     * resolvendo o chamado. A primeira resposta marca first_response_at; chamado novo passa a "em atendimento".
     *
     * @param array<string, mixed>|null $files $_FILES['attachments'] (vários)
     * @return array{message_id:int, status:string}
     * @throws TicketError
     */
    public function reply(User $agent, int $ticketId, string $message, bool $internal, int $seconds = 0, bool $resolve = false, ?array $files = null): array
    {
        $message = trim(strip_tags($message));
        if (mb_strlen($message) < 2) {
            throw new TicketError('message', 'Escreva uma resposta antes de enviar.');
        }
        $current = $this->pdo->prepare('SELECT status FROM studio_support_tickets WHERE id = ?');
        $current->execute([$ticketId]);
        $before = $current->fetchColumn();
        if ($before === false) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
        $seconds = max(0, min(86400, $seconds));
        $status = $resolve ? 'resolved' : ((string)$before === 'open' ? 'in_progress' : (string)$before);
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('INSERT INTO studio_support_ticket_messages (ticket_id, user_id, message, is_internal) VALUES (?, ?, ?, ?)')
                ->execute([$ticketId, (int)$agent->id, mb_substr($message, 0, 10000), $internal ? 1 : 0]);
            $messageId = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE studio_support_tickets SET status = :status, first_response_at = COALESCE(first_response_at, NOW()),
                work_seconds = work_seconds + :seconds, resolved_at = ' . ($resolve ? 'COALESCE(resolved_at, NOW())' : 'resolved_at') . ' WHERE id = :id')
                ->execute(['status' => $status, 'seconds' => $seconds, 'id' => $ticketId]);
            if ($status !== (string)$before) {
                $this->event($ticketId, (int)$agent->id, 'status', (string)$before, $status);
            }
            $this->attach($ticketId, $messageId, (int)$agent->id, $files);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
        Audit::record('reply', 'studio_support_tickets', $ticketId, ['status' => (string)$before], ['status' => $status, 'message_id' => $messageId,
            'internal' => $internal, 'work_seconds' => $seconds]);
        if ($status !== (string)$before) {
            (new Occurrences($this->pdo))->followTickets([$ticketId], (int)$agent->id);
        }
        if (!$internal) {
            $this->notify($ticketId, 'reply', (int)$agent->id, $message);
        }
        return ['message_id' => $messageId, 'status' => $status];
    }

    /**
     * Situação em lote. $allowed limita aos chamados que quem pediu pode ver (o ERP passa os do escopo).
     *
     * @param list<int> $ticketIds
     * @param list<int>|null $allowed
     * @return int chamados alterados
     */
    public function bulkStatus(User $agent, array $ticketIds, string $status, ?array $allowed = null): int
    {
        if (!in_array($status, TicketService::STATUSES, true)) {
            throw new TicketError('status', 'Escolha uma situação válida.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ticketIds), static fn (int $id): bool => $id > 0)));
        if ($allowed !== null) {
            $ids = array_values(array_intersect($ids, $allowed));
        }
        $changed = 0;
        foreach (array_slice($ids, 0, 200) as $id) {
            $changed += $this->update($agent, $id, ['status' => $status])['changed'] !== [] ? 1 : 0;
        }
        return $changed;
    }

    /**
     * Números da fila (os contadores do topo). $condominiumIds null = todos (Help Desk); no ERP, os do escopo,
     * mais os chamados que o usuário abriu ou pediu.
     *
     * @param list<int>|null $condominiumIds
     * @return array{by_status:array<string,int>, total:int, overdue:int, due_today:int, unassigned:int, open_total:int}
     */
    public function metrics(?array $condominiumIds = null, ?int $userId = null): array
    {
        [$where, $params] = $this->scopeWhere($condominiumIds, $userId);
        $stmt = $this->pdo->prepare("SELECT status, COUNT(*) AS total,
                SUM(status NOT IN ('resolved','closed') AND due_at < NOW()) AS overdue,
                SUM(status NOT IN ('resolved','closed') AND DATE(due_at) = CURDATE()) AS due_today,
                SUM(status NOT IN ('resolved','closed') AND assigned_to IS NULL) AS unassigned
            FROM studio_support_tickets t WHERE {$where} GROUP BY status");
        $stmt->execute($params);
        $result = ['by_status' => array_fill_keys(TicketService::STATUSES, 0), 'total' => 0, 'overdue' => 0, 'due_today' => 0, 'unassigned' => 0, 'open_total' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result['by_status'][(string)$row['status']] = (int)$row['total'];
            $result['total'] += (int)$row['total'];
            $result['overdue'] += (int)$row['overdue'];
            $result['due_today'] += (int)$row['due_today'];
            $result['unassigned'] += (int)$row['unassigned'];
            if (!in_array($row['status'], self::FINAL, true)) {
                $result['open_total'] += (int)$row['total'];
            }
        }
        return $result;
    }

    /** Medições do chamado: primeira resposta, tempo dedicado, respostas e prazo. */
    public function facts(int $ticketId): array
    {
        $stmt = $this->pdo->prepare('SELECT first_response_at, work_seconds, team, tags, updated_at,
            (SELECT COUNT(*) FROM studio_support_ticket_messages m WHERE m.ticket_id = t.id) AS replies,
            (SELECT COUNT(*) FROM studio_support_ticket_messages m WHERE m.ticket_id = t.id AND m.is_internal = 1) AS internal_notes
            FROM studio_support_tickets t WHERE t.id = ?');
        $stmt->execute([$ticketId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return [
            'first_response_at' => ($row['first_response_at'] ?? null) !== null ? (string)$row['first_response_at'] : null,
            'work_seconds' => (int)($row['work_seconds'] ?? 0),
            'team' => ($row['team'] ?? null) !== null ? (string)$row['team'] : null,
            'tags' => ($row['tags'] ?? null) !== null ? (string)$row['tags'] : null,
            'updated_at' => ($row['updated_at'] ?? null) !== null ? (string)$row['updated_at'] : null,
            'replies' => (int)($row['replies'] ?? 0),
            'internal_notes' => (int)($row['internal_notes'] ?? 0),
        ];
    }

    /** Histórico de alterações, do mais novo para o mais antigo. @return list<array{type:string,label:string,old:?string,new:?string,author:string,created_at:string}> */
    public function events(int $ticketId): array
    {
        $stmt = $this->pdo->prepare("SELECT e.event_type, e.old_value, e.new_value, e.created_at, NULLIF(TRIM(CONCAT(u.first_name, ' ', u.last_name)), '') AS author
            FROM studio_support_ticket_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.ticket_id = ? ORDER BY e.created_at DESC, e.id DESC LIMIT 200");
        $stmt->execute([$ticketId]);
        $names = [];
        $label = function (string $type, ?string $value) use (&$names): ?string {
            if ($value === null || $value === '') {
                return $value;
            }
            return match ($type) {
                'status' => TicketService::STATUS_LABELS[$value] ?? $value,
                'priority' => TicketService::PRIORITY_LABELS[$value] ?? $value,
                'origin' => TicketService::ORIGIN_LABELS[$value] ?? $value,
                'area' => TicketService::AREA_LABELS[$value] ?? $value,
                'assigned_to' => $names[$value] ??= (($u = (new User())->findById((int)$value)) ? $u->fullName() : '#' . $value),
                default => $value,
            };
        };
        return array_map(static fn (array $r): array => ['type' => (string)$r['event_type'], 'label' => self::EVENT_LABELS[$r['event_type']] ?? (string)$r['event_type'],
            'old' => $label((string)$r['event_type'], $r['old_value']), 'new' => $label((string)$r['event_type'], $r['new_value']),
            'author' => (string)($r['author'] ?? 'Sistema'), 'created_at' => (string)$r['created_at']], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** Respostas rápidas ativas (as mesmas do Help Desk). @return list<array{id:int,title:string,body:string}> */
    public function templates(): array
    {
        $rows = $this->pdo->query('SELECT id, title, body FROM studio_support_templates WHERE active = 1 ORDER BY title')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'body' => (string)$r['body']], $rows);
    }

    /** Quem pode ser responsável: a equipe (nível de administrador do Help Desk). @return list<array{id:int,name:string}> */
    public function agents(): array
    {
        $rows = $this->pdo->query("SELECT id, NULLIF(TRIM(CONCAT(first_name, ' ', last_name)), '') AS name FROM users WHERE status <> 'trash' AND level >= 5 ORDER BY first_name, last_name")
            ->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)($r['name'] ?? ('#' . $r['id']))], $rows);
    }

    /** "Vencido", "Finalizado" ou o prazo, como na fila do Help Desk (regra pura). */
    public static function slaState(string $status, string $dueAt, ?int $now = null): string
    {
        if (in_array($status, self::FINAL, true)) {
            return 'done';
        }
        return strtotime($dueAt) < ($now ?? time()) ? 'late' : 'on_time';
    }

    private function scopeWhere(?array $condominiumIds, ?int $userId): array
    {
        if ($condominiumIds === null) {
            return ['1 = 1', []];
        }
        $visible = [];
        $params = [];
        if ($userId !== null) {
            $visible[] = 't.created_by = :me OR t.requester_id = :me2';
            $params['me'] = $userId;
            $params['me2'] = $userId;
        }
        if ($condominiumIds !== []) {
            $in = [];
            foreach (array_values($condominiumIds) as $i => $id) {
                $in[] = ':c' . $i;
                $params['c' . $i] = (int)$id;
            }
            $visible[] = 't.condominium_id IN (' . implode(',', $in) . ')';
        }
        return [$visible === [] ? '1 = 0' : '(' . implode(' OR ', $visible) . ')', $params];
    }

    private function userId(mixed $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $id === false || $id === null || !(new User())->findById((int)$id) ? null : (int)$id;
    }

    private function event(int $ticketId, int $userId, string $type, ?string $old, ?string $new): void
    {
        $this->pdo->prepare('INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, old_value, new_value) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ticketId, $userId, $type, $old, $new]);
    }

    /** @param array<string, mixed>|null $files */
    private function attach(int $ticketId, int $messageId, int $userId, ?array $files): void
    {
        if ($files === null || empty($files['tmp_name']) || !is_array($files['tmp_name'])) {
            return;
        }
        foreach ($files['tmp_name'] as $index => $temporary) {
            if (!$temporary) {
                continue;
            }
            $file = ['name' => $files['name'][$index] ?? 'arquivo', 'type' => $files['type'][$index] ?? '', 'tmp_name' => $temporary,
                'error' => $files['error'][$index] ?? UPLOAD_ERR_NO_FILE, 'size' => $files['size'][$index] ?? 0];
            $path = (new Upload())->file($file, 'ticket-' . $ticketId . '-' . bin2hex(random_bytes(4)));
            if (!$path) {
                throw new TicketError('attachments', 'Um dos anexos não é válido.');
            }
            $this->pdo->prepare('INSERT INTO studio_support_ticket_attachments (ticket_id, message_id, user_id, file_path, original_name, mime_type, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$ticketId, $messageId, $userId, $path, mb_substr(basename((string)$file['name']), 0, 255), $file['type'] ?: null, (int)$file['size']]);
        }
    }

    private function notify(int $ticketId, string $kind, int $authorId, ?string $text = null): void
    {
        try {
            (new TicketService($this->pdo))->notifyRequester($ticketId, $kind, $authorId, $text);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'tickets', ['event_type' => 'ticket_requester_notification_failed', 'ticket_id' => $ticketId]);
        }
    }
}
