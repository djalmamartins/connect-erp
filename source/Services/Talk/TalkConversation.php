<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Support\TicketError;
use Source\Services\Support\TicketService;
use Source\Support\RichText;

/**
 * A conversa dos chamados do Connect Talk nos dois sentidos (ADR 0634, escopo tickets.write):
 *
 * - feed(): o que a equipe respondeu nos chamados que o Talk abriu, para o Talk mandar no WhatsApp. Cursor pelo id da
 *   mensagem (`after`): o Talk guarda o `next_after` e pede de novo. Só mensagens públicas (nota interna nunca sai) e só
 *   as que não foram escritas pelo próprio usuário de integração (o que veio do WhatsApp o Talk já tem).
 * - show(): o chamado com a conversa pública inteira.
 * - post(): a mensagem que o morador mandou no WhatsApp entra no chamado como resposta de quem pediu
 *   (TicketService::replyAsRequester: aguardando o cliente volta para atendimento, resolvido ou fechado reabre e a
 *   equipe é avisada). Idempotente pelo `talk_message_id`.
 *
 * Só chamado de origem "talk" e do escopo do usuário de integração (ou sem condomínio e aberto por ele); senão 404.
 * Da equipe sai só o primeiro nome de quem respondeu.
 */
final class TalkConversation
{
    public const FEED_LIMIT = 100;
    /** Sem cursor (after=0), o feed começa nas últimas 24 horas, para não despejar o histórico inteiro. */
    public const FIRST_WINDOW_HOURS = 24;
    public const REF_MAX = 64;

    private PDO $pdo;
    private CondominiumScope $scope;

    public function __construct(?PDO $pdo = null, ?CondominiumScope $scope = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->scope = $scope ?? new CondominiumScope($this->pdo);
    }

    /** Cursor do feed (regra pura): inteiro >= 0, senão 0. */
    public static function cursor(mixed $value): int
    {
        $n = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return $n === false ? 0 : (int)$n;
    }

    /** Primeiro nome de quem respondeu (regra pura); sem nome, "Equipe Connect". */
    public static function firstName(?string $name): string
    {
        $first = strtok(trim((string)$name), ' ');
        return $first !== false && $first !== '' ? $first : 'Equipe Connect';
    }

    /**
     * @return array{data: list<array{id:int, ticket:array, author:string, body:string, attachments:int, created_at:string}>,
     *   meta: array{after:int, next_after:int, has_more:bool}}
     */
    public function feed(User $agent, mixed $after = 0): array
    {
        $after = self::cursor($after);
        [$where, $params] = $this->ticketScope($agent);
        $sql = "SELECT m.id, m.ticket_id, m.message, m.created_at, u.first_name,
                (SELECT COUNT(*) FROM studio_support_ticket_attachments a WHERE a.message_id = m.id) AS files
            FROM studio_support_ticket_messages m
            JOIN studio_support_tickets t ON t.id = m.ticket_id
            LEFT JOIN users u ON u.id = m.user_id
            WHERE t.origin = 'talk' AND {$where} AND m.is_internal = 0 AND m.id > ?
              AND (m.user_id IS NULL OR m.user_id <> ?)";
        $params[] = $after;
        $params[] = (int)$agent->id;
        if ($after === 0) {
            $sql .= ' AND m.created_at >= (NOW() - INTERVAL ' . self::FIRST_WINDOW_HOURS . ' HOUR)';
        }
        $stmt = $this->pdo->prepare($sql . ' ORDER BY m.id LIMIT ' . (self::FEED_LIMIT + 1));
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $more = count($rows) > self::FEED_LIMIT;
        $rows = array_slice($rows, 0, self::FEED_LIMIT);
        $summaries = [];
        $helpdesk = new TalkHelpdesk($this->pdo, $this->scope);
        $out = [];
        foreach ($rows as $row) {
            $ticketId = (int)$row['ticket_id'];
            $summaries[$ticketId] ??= $helpdesk->summary($ticketId);
            $out[] = ['id' => (int)$row['id'], 'ticket' => $summaries[$ticketId], 'author' => self::firstName($row['first_name']),
                'body' => RichText::plain((string)$row['message']), 'attachments' => (int)$row['files'], 'created_at' => (string)$row['created_at']];
        }
        $last = $out !== [] ? $out[count($out) - 1]['id'] : $after;
        return ['data' => $out, 'meta' => ['after' => $after, 'next_after' => $last, 'has_more' => $more]];
    }

    /** O chamado com a conversa pública. `from_talk` marca o que veio do WhatsApp (escrito pelo usuário de integração). */
    public function show(User $agent, int $ticketId): array
    {
        $this->assertTicket($agent, $ticketId);
        $summary = (new TalkHelpdesk($this->pdo, $this->scope))->summary($ticketId);
        $stmt = $this->pdo->prepare("SELECT m.id, m.user_id, m.message, m.created_at, u.first_name,
                (SELECT COUNT(*) FROM studio_support_ticket_attachments a WHERE a.message_id = m.id) AS files
            FROM studio_support_ticket_messages m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = ? AND m.is_internal = 0 ORDER BY m.id");
        $stmt->execute([$ticketId]);
        $messages = array_map(static fn (array $r): array => [
            'id' => (int)$r['id'],
            'from_talk' => (int)($r['user_id'] ?? 0) === (int)$agent->id,
            'author' => (int)($r['user_id'] ?? 0) === (int)$agent->id ? 'WhatsApp' : self::firstName($r['first_name']),
            'body' => RichText::plain((string)$r['message']),
            'attachments' => (int)$r['files'],
            'created_at' => (string)$r['created_at'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $summary + ['messages' => $messages];
    }

    /**
     * Mensagem do WhatsApp no chamado. Repetir o mesmo talk_message_id devolve a primeira, sem duplicar.
     *
     * @return array{message_id:int, status:string, duplicate:bool}
     * @throws TicketError
     */
    public function post(User $agent, int $ticketId, array $input): array
    {
        $this->assertTicket($agent, $ticketId);
        $ref = preg_replace('/[^0-9A-Za-z._:-]/', '', mb_substr(trim((string)($input['talk_message_id'] ?? '')), 0, self::REF_MAX)) ?? '';
        if ($ref !== '') {
            $seen = $this->pdo->prepare("SELECT old_value FROM studio_support_ticket_events WHERE ticket_id = ? AND event_type = 'talk_message' AND new_value = ? LIMIT 1");
            $seen->execute([$ticketId, $ref]);
            $previous = $seen->fetchColumn();
            if ($previous !== false) {
                $status = $this->pdo->prepare('SELECT status FROM studio_support_tickets WHERE id = ?');
                $status->execute([$ticketId]);
                return ['message_id' => (int)$previous, 'status' => (string)$status->fetchColumn(), 'duplicate' => true];
            }
        }
        // Texto do WhatsApp: nunca HTML.
        $message = trim(strip_tags((string)($input['message'] ?? '')));
        $result = (new TicketService($this->pdo, $this->scope))->replyAsRequester($agent, $ticketId, $message);
        if ($ref !== '') {
            $this->pdo->prepare("INSERT INTO studio_support_ticket_events (ticket_id, user_id, event_type, old_value, new_value) VALUES (?, ?, 'talk_message', ?, ?)")
                ->execute([$ticketId, (int)$agent->id, (string)$result['message_id'], $ref]);
        }
        return $result + ['duplicate' => false];
    }

    private function assertTicket(User $agent, int $ticketId): void
    {
        [$where, $params] = $this->ticketScope($agent);
        $stmt = $this->pdo->prepare("SELECT 1 FROM studio_support_tickets t WHERE t.id = ? AND t.origin = 'talk' AND {$where}");
        $stmt->execute([$ticketId, ...$params]);
        if ($ticketId <= 0 || !$stmt->fetchColumn()) {
            throw new TicketError('ticket', 'Chamado não encontrado.', 404);
        }
    }

    /** @return array{0:string, 1:list<int>} condomínios do escopo, ou sem condomínio e aberto pelo próprio usuário de integração */
    private function ticketScope(User $agent): array
    {
        $allowed = $this->scope->allowedIds($agent);
        $own = '(t.condominium_id IS NULL AND t.created_by = ?)';
        if ($allowed === []) {
            return [$own, [(int)$agent->id]];
        }
        $marks = implode(',', array_fill(0, count($allowed), '?'));
        return ["(t.condominium_id IN ({$marks}) OR {$own})", [...$allowed, (int)$agent->id]];
    }
}
