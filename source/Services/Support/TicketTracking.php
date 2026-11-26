<?php

declare(strict_types=1);

namespace Source\Services\Support;

use PDO;
use Source\Core\Connect;
use Source\Services\Talk\TalkHelpdesk;
use Source\Support\RichText;

/**
 * Acompanhamento público do chamado pelo link (ADR 0649): protocolo + código de acesso do chamado, sem login.
 * É o link que a Jack manda pelo WhatsApp. Mostra só o mínimo (LGPD): assunto, situação, área, datas e as
 * respostas públicas. A descrição da abertura (com telefone, condomínio e a conversa) e as notas internas não saem.
 */
final class TicketTracking
{
    public const CODE = '/^[a-f0-9]{64}$/';

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /**
     * @return array{protocol:string, subject:string, status:string, status_label:string, area_label:string, created_at:string,
     *               updated_at:string, closed:bool, messages:list<array{author:string, mine:bool, body:string, attachments:int, created_at:string}>}|null
     */
    public function find(string $protocol, string $code): ?array
    {
        $protocol = strtoupper(trim($protocol));
        $code = strtolower(trim($code));
        if (!TicketProtocol::isValid($protocol) || !preg_match(self::CODE, $code)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, protocol, subject, status, area, origin, created_by, access_token, created_at, updated_at
            FROM studio_support_tickets WHERE protocol = ? AND access_token IS NOT NULL LIMIT 1');
        $stmt->execute([$protocol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals((string)$row['access_token'], $code)) {
            return null;
        }
        $messages = $this->pdo->prepare('SELECT m.user_id, m.message, m.created_at, u.first_name,
                (SELECT COUNT(*) FROM studio_support_ticket_attachments a WHERE a.message_id = m.id) AS files
            FROM studio_support_ticket_messages m LEFT JOIN users u ON u.id = m.user_id
            WHERE m.ticket_id = ? AND m.is_internal = 0 ORDER BY m.id');
        $messages->execute([(int)$row['id']]);
        // quem pediu: sem conta (widget) ou o usuário de integração do Talk (o que veio do WhatsApp), menos o
        // texto de encerramento que o Talk deixa em nome da equipe
        $requester = static fn (array $m): bool => $m['user_id'] === null
            || ($row['origin'] === 'talk' && $row['created_by'] !== null && (int)$m['user_id'] === (int)$row['created_by']
                && !str_starts_with(RichText::plain((string)$m['message']), TalkHelpdesk::CLOSED_TEXT));
        $list = [];
        foreach ($messages->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $mine = $requester($m);
            $fromTalk = !$mine && $row['origin'] === 'talk' && $m['user_id'] !== null && (int)$m['user_id'] === (int)$row['created_by'];
            $list[] = [
                'author' => $mine ? 'Você' : (!$fromTalk && trim((string)($m['first_name'] ?? '')) !== '' ? trim(explode(' ', trim((string)$m['first_name']))[0]) : 'Equipe Connect'),
                'mine' => $mine,
                'body' => RichText::plain((string)$m['message']),
                'attachments' => (int)$m['files'],
                'created_at' => (string)$m['created_at'],
            ];
        }
        $status = (string)$row['status'];
        return [
            'protocol' => (string)$row['protocol'],
            'subject' => (string)$row['subject'],
            'status' => $status,
            'status_label' => TicketService::STATUS_LABELS[$status] ?? $status,
            'area_label' => TicketService::AREA_LABELS[(string)$row['area']] ?? 'Geral',
            'created_at' => (string)$row['created_at'],
            'updated_at' => (string)($row['updated_at'] ?? $row['created_at']),
            'closed' => in_array($status, ['resolved', 'closed'], true),
            'messages' => $list,
        ];
    }
}
