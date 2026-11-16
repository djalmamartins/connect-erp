<?php
declare(strict_types=1);

namespace Source\Services\Talk\Erp;

use Source\Models\User;
use Source\Services\Talk\Contracts\Helpdesk;
use Source\Services\Talk\Support\Db;
use Source\Services\Talk\TalkHelpdesk;

/**
 * Chamado da conversa direto no Help Desk do ERP (ADR 0324), sem a API: mesmas regras de TalkHelpdesk (origem "talk",
 * idempotente pelo protocolo do Talk, sem e-mail). O atendente do Talk (talk_users) é o usuário do ERP de
 * talk_users.erp_user_id, que é quem aparece no chamado ao transferir ou encerrar. Atendente sem usuário do ERP
 * (perfil antigo, de antes da mudança): a transferência ou o encerramento no chamado é pulado e registrado no log,
 * porque a conversa no Talk não pode parar por isso.
 */
final class LocalHelpdesk implements Helpdesk
{
    /** Setor do Talk → área do Help Desk (AREAS do TicketService). */
    public const AREA_BY_SECTOR = ['financeiro' => 'financial', 'manutencao' => 'technical', 'administrativo' => 'general'];

    public function __construct(private TalkHelpdesk $erp, private Db $db, private string $talkUrl = '', private ?\Closure $logger = null)
    {
    }

    public static function area(?string $sectorName): string
    {
        $key = strtolower(strtr((string) $sectorName, ['ç' => 'c', 'ã' => 'a', 'Ç' => 'c', 'Ã' => 'a']));
        return self::AREA_BY_SECTOR[$key] ?? 'general';
    }

    public function open(array $conversation, array $contact, ?string $sectorName): ?array
    {
        $details = is_array($contact['details'] ?? null) ? $contact['details'] : [];
        $protocol = (string) $conversation['protocol'];
        $message = trim((string) ($conversation['ticket_message'] ?? ''));
        $result = $this->erp->open(null, [
            'talk_protocol' => $protocol,
            'subject' => $conversation['subject'] ?? ('WhatsApp ' . $protocol),
            'message' => $message !== ''
                ? $message . "\n\n(Conversa de WhatsApp no Connect Talk, protocolo " . $protocol . '.)'
                : 'Conversa de WhatsApp no Connect Talk, protocolo ' . $protocol . '.',
            'area' => self::area($sectorName),
            'resident_id' => ($contact['erp_person_id'] ?? null) !== null ? (int) $contact['erp_person_id'] : null,
            'condominium_id' => isset($details['condominio_id']) ? (int) $details['condominio_id'] : null,
            'unit_id' => isset($details['unidade_id']) ? (int) $details['unidade_id'] : null,
            'requester_name' => ($contact['name'] ?? '') ?: null,
            'requester_phone' => $contact['phone'] ?? null,
            'conversation_url' => $this->talkUrl !== '' ? rtrim($this->talkUrl, '/') . '/?c=' . $conversation['id'] : '',
        ]);
        $ticket = $result['ticket'];
        $number = trim((string) ($ticket['protocol'] ?? ''));
        return ['id' => (int) $ticket['id'], 'protocol' => $number !== '' ? mb_substr($number, 0, 40) : null];
    }

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void
    {
        $agent = $this->erpUser($byUserId);
        if ($agent === null) {
            $this->log("transferência do chamado #$ticketId pulada: atendente #$byUserId do Talk sem usuário do ERP");
            return;
        }
        $this->erp->transfer($agent, $ticketId, ['area' => self::area($toSectorName), 'sector' => $toSectorName, 'note' => $note, 'talk_user_id' => $byUserId]);
    }

    public function close(int $ticketId, int $byUserId, ?string $resolution): void
    {
        $agent = $this->erpUser($byUserId);
        if ($agent === null) {
            $this->log("encerramento do chamado #$ticketId pulado: atendente #$byUserId do Talk sem usuário do ERP");
            return;
        }
        $this->erp->close($agent, $ticketId, ['resolution' => $resolution]);
    }

    /** Usuário do ERP do atendente do Talk, ou null. */
    private function erpUser(int $talkUserId): ?User
    {
        $row = $this->db->one('SELECT erp_user_id FROM talk_users WHERE id = ?', [$talkUserId]);
        if ($row === null || $row['erp_user_id'] === null) {
            return null;
        }
        $user = (new User())->findById((int) $row['erp_user_id']);
        return $user ?: null;
    }

    private function log(string $message): void
    {
        $this->logger && ($this->logger)($message);
    }
}
