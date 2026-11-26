<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;
use Source\Services\Support\TicketService;

/**
 * Avisos do chamado no app do morador (ADR 0642). Cada movimentação da equipe no chamado (abertura, resposta,
 * situação, equipe, responsável) vira um aviso para quem pediu: aparece no sininho do app (web e celular) e, se o
 * morador tem o app no celular, sai também como push (ResidentPush::dispatch).
 *
 * - Nota interna nunca vira aviso (quem chama só registra a resposta ao cliente).
 * - Não manda e-mail: o morador recebe só os 5 e-mails da ADR 0601.
 * - O próprio autor da mudança não é avisado, salvo na abertura ("Recebemos o seu chamado"), que confirma o protocolo.
 * - O sininho só mostra os avisos dos chamados do condomínio do vínculo escolhido no app.
 */
final class ResidentTicketNotices
{
    public const EVENTS = ['opened', 'reply', 'status', 'team', 'assigned'];
    public const FEED_DAYS = 30;
    public const FEED_LIMIT = 20;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * Registra o aviso da movimentação. $changes traz o que mudou: status, team, assigned_to (id de quem atende),
     * text (resposta ao cliente). Devolve o id do aviso, ou null quando não há a quem avisar.
     *
     * @param array{status?:string, team?:?string, assigned_to?:?int, text?:string} $changes
     */
    public function record(int $ticketId, string $event, int $authorId, array $changes = []): ?int
    {
        if (!in_array($event, self::EVENTS, true)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, protocol, subject, status, team, requester_id, condominium_id FROM studio_support_tickets WHERE id = ?');
        $stmt->execute([$ticketId]);
        $ticket = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($ticket) || $ticket['requester_id'] === null || $ticket['condominium_id'] === null) {
            return null;
        }
        $recipient = (int)$ticket['requester_id'];
        if ($recipient === $authorId && $event !== 'opened') {
            return null;
        }
        if (array_key_exists('assigned_to', $changes) && $changes['assigned_to'] !== null) {
            $name = $this->pdo->prepare('SELECT first_name FROM users WHERE id = ?');
            $name->execute([(int)$changes['assigned_to']]);
            $changes['assigned_name'] = trim((string)$name->fetchColumn());
        }
        $notice = self::describe($event, $ticket, $changes);
        if ($notice === null) {
            return null;
        }
        $tokens = $this->pdo->prepare('SELECT COUNT(*) FROM resident_push_tokens WHERE user_id = ?');
        $tokens->execute([$recipient]);
        $this->pdo->prepare('INSERT INTO resident_ticket_notices (user_id, ticket_id, event, title, detail, tone, push_status) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$recipient, $ticketId, $notice['event'], mb_substr($notice['title'], 0, 160), mb_substr($notice['detail'], 0, 255), $notice['tone'],
                (int)$tokens->fetchColumn() > 0 ? 'pending' : 'none']);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Texto do aviso (regra pura). Várias mudanças de uma vez (situação + equipe + responsável) viram um aviso só,
     * com o título da mais importante.
     *
     * @param array{protocol:string, subject:string, status?:string} $ticket
     * @return array{event:string, title:string, detail:string, tone:string}|null
     */
    public static function describe(string $event, array $ticket, array $changes = []): ?array
    {
        $protocol = (string)$ticket['protocol'];
        $noun = str_starts_with(strtoupper($protocol), 'OC') ? 'Ocorrência' : 'Chamado';
        $subject = mb_strimwidth(trim((string)$ticket['subject']), 0, 80, '…');
        if ($event === 'opened') {
            return ['event' => 'opened', 'title' => "{$noun} {$protocol} " . ($noun === 'Ocorrência' ? 'aberta' : 'aberto'), 'tone' => 'info',
                'detail' => "Recebemos: {$subject}. Avisaremos você a cada andamento."];
        }
        $sentences = [];
        $tone = 'info';
        $title = null;
        $primary = $event;
        if ($event === 'reply') {
            $text = mb_strimwidth(trim((string)preg_replace('/\s+/', ' ', (string)($changes['text'] ?? ''))), 0, 140, '…');
            $title = $noun === 'Ocorrência' ? "Resposta na ocorrência {$protocol}" : "Resposta no chamado {$protocol}";
            $sentences[] = $text !== '' ? $text : 'A equipe Connect respondeu.';
        }
        if (isset($changes['status']) && is_string($changes['status']) && $changes['status'] !== '') {
            $status = $changes['status'];
            $label = TicketService::STATUS_LABELS[$status] ?? $status;
            $tone = match ($status) {
                'waiting_customer' => 'warning',
                'resolved', 'closed' => 'success',
                default => 'info',
            };
            if ($title === null) {
                $title = "{$noun} {$protocol}: {$label}";
                $primary = 'status';
            } elseif (in_array($status, ['resolved', 'closed'], true)) {
                $title .= ' (' . mb_strtolower($label) . ')';
            }
            if ($event !== 'reply') {
                $sentences[] = match ($status) {
                    'open' => 'O chamado foi reaberto.',
                    'in_progress' => 'A equipe está cuidando do seu chamado.',
                    'waiting_customer' => 'A equipe precisa de uma resposta sua. Abra o chamado para responder.',
                    'resolved' => 'A equipe marcou como resolvido. Se ainda precisar de algo, responda no chamado.',
                    'closed' => 'O chamado foi encerrado.',
                    default => "Situação: {$label}.",
                };
            }
        }
        if (array_key_exists('team', $changes) && $changes['team'] !== null && trim((string)$changes['team']) !== '') {
            $team = trim((string)$changes['team']);
            if ($title === null) {
                $title = "{$noun} {$protocol} com a equipe {$team}";
                $primary = 'team';
            }
            $sentences[] = "Encaminhado para a equipe {$team}.";
        }
        if (array_key_exists('assigned_to', $changes) && $changes['assigned_to'] !== null) {
            $who = trim((string)($changes['assigned_name'] ?? ''));
            if ($title === null) {
                $title = "{$noun} {$protocol} tem um responsável";
                $primary = 'assigned';
            }
            $sentences[] = $who !== '' ? "{$who}, da equipe Connect, vai acompanhar o seu chamado." : 'Uma pessoa da equipe Connect vai acompanhar o seu chamado.';
        }
        if ($title === null || $sentences === []) {
            return null;
        }
        return ['event' => $primary, 'title' => $title, 'detail' => implode(' ', $sentences), 'tone' => $tone];
    }

    /**
     * Avisos do sininho para o vínculo: os chamados que o morador pediu no condomínio escolhido, últimos 30 dias.
     *
     * @return list<array{kind:string, title:string, detail:string, at:string, path:string, tone:string}>
     */
    public function feed(int $userId, int $condominiumId, ?string $today = null): array
    {
        $since = (new \DateTimeImmutable($today ?? date('Y-m-d')))->modify('-' . self::FEED_DAYS . ' days')->format('Y-m-d 00:00:00');
        $stmt = $this->pdo->prepare('SELECT n.ticket_id, n.title, n.detail, n.tone, n.created_at FROM resident_ticket_notices n
            JOIN studio_support_tickets t ON t.id = n.ticket_id
            WHERE n.user_id = :u AND t.requester_id = :u2 AND t.condominium_id = :c AND n.created_at >= :since
            ORDER BY n.created_at DESC, n.id DESC LIMIT ' . self::FEED_LIMIT);
        $stmt->execute(['u' => $userId, 'u2' => $userId, 'c' => $condominiumId, 'since' => $since]);
        $items = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $items[] = ['kind' => 'ticket', 'title' => (string)$row['title'], 'detail' => (string)$row['detail'], 'at' => (string)$row['created_at'],
                'path' => self::path((int)$row['ticket_id']), 'tone' => (string)$row['tone']];
        }
        return $items;
    }

    /** Caminho do chamado no app web; o app do celular troca por /chamados/{id}. */
    public static function path(int $ticketId): string
    {
        return '/ocorrencias/' . $ticketId;
    }
}
