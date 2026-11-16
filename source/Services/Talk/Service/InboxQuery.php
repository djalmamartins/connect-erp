<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/** Listas da caixa de entrada e busca de mensagens, sempre filtradas pela permissão. */
final class InboxQuery
{
    public function __construct(private Db $db, private AccessPolicy $policy)
    {
    }

    /**
     * @param string $tab pending | open | closed | mine
     */
    public function list(StaffMember $u, string $tab, ?int $sectorId = null, int $limit = 50, int $offset = 0, ?int $tagId = null): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $conds = [$where];
        switch ($tab) {
            case 'mine':
                $conds[] = "c.status = 'open' AND c.assigned_user_id = :me2";
                $params['me2'] = $u->userId;
                break;
            case 'pending':
            case 'open':
            case 'closed':
                $conds[] = 'c.status = :status';
                $params['status'] = $tab;
                break;
            default:
                throw new DomainError('Aba inválida.');
        }
        if ($sectorId !== null) {
            $conds[] = 'c.sector_id = :sector';
            $params['sector'] = $sectorId;
        }
        if ($tagId !== null) {
            $conds[] = 'EXISTS (SELECT 1 FROM talk_contact_tags ft WHERE ft.contact_id = c.contact_id AND ft.tag_id = :tag)';
            $params['tag'] = $tagId;
        }
        $sql = 'SELECT c.id, c.protocol, c.status, c.stage, c.sector_id, s.name AS sector_name, c.assigned_user_id, c.unread,
                       c.last_message_at, ct.name AS contact_name, ct.phone, ct.erp_person_id, ct.erp_snapshot,
                       (SELECT GROUP_CONCAT(tg.tag_id ORDER BY tg.tag_id) FROM talk_contact_tags tg WHERE tg.contact_id = c.contact_id) AS tag_ids,
                       (SELECT COALESCE(NULLIF(m.body, \'\'), CASE m.type WHEN \'image\' THEN \'📷 Foto\' WHEN \'audio\' THEN \'🎤 Áudio\' WHEN \'video\' THEN \'🎬 Vídeo\' WHEN \'document\' THEN \'📄 Documento\' END)
                          FROM talk_messages m WHERE m.conversation_id = c.id AND m.direction <> \'internal\' ORDER BY m.id DESC LIMIT 1) AS last_body
                FROM talk_conversations c
                JOIN talk_contacts ct ON ct.id = c.contact_id
                LEFT JOIN talk_sectors s ON s.id = c.sector_id
                WHERE ' . implode(' AND ', $conds) . '
                ORDER BY c.last_message_at DESC
                LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset;
        return $this->db->all($sql, $params);
    }

    /** Mensagens de uma conversa (inclui notas internas). */
    public function messages(StaffMember $u, int $convId, ?int $beforeId = null, int $limit = 50): array
    {
        $conv = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$convId]);
        if (!$conv || !$this->policy->canView($u, $conv)) {
            throw new DomainError('Sem acesso a esta conversa.', 403);
        }
        $params = [$convId];
        $extra = '';
        // transferida sem "manter histórico": quem recebeu vê a partir da transferência
        $sector = $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null;
        if ($conv['history_from_message_id'] && !$u->supervisor && !in_array($sector, $u->managerSectors, true)) {
            $extra .= ' AND id >= ?';
            $params[] = (int) $conv['history_from_message_id'];
        }
        if ($beforeId) {
            $extra .= ' AND id < ?'; // .= : não pode apagar o filtro de histórico acima
            $params[] = $beforeId;
        }
        $rows = $this->db->all(
            'SELECT id, direction, author_user_id, type, body, media_path, media_mime, media_name, media_size, ack, created_at
             FROM talk_messages WHERE conversation_id = ?' . $extra . ' ORDER BY id DESC LIMIT ' . (int) $limit,
            $params
        );
        return array_reverse($rows);
    }

    /**
     * Arquivo de uma mensagem, para download: mesma regra de quem vê a conversa (inclusive histórico oculto).
     * @return array{media_path:string, media_mime:string, media_name:?string}
     */
    public function mediaFor(StaffMember $u, int $messageId): array
    {
        $m = $this->db->one('SELECT id, conversation_id, media_path, media_mime, media_name FROM talk_messages WHERE id = ?', [$messageId]);
        if (!$m || $m['media_path'] === null) {
            throw new DomainError('Arquivo não encontrado.', 404);
        }
        $visible = array_column($this->messagesAround($u, (int) $m['conversation_id'], $messageId), 'id');
        if (!in_array((int) $m['id'], array_map('intval', $visible), true)) {
            throw new DomainError('Sem acesso a este arquivo.', 403);
        }
        if (!str_starts_with((string) $m['media_path'], 'media/')) {
            throw new DomainError(str_starts_with((string) $m['media_path'], 'failed:') ? 'Arquivo indisponível no WhatsApp.' : 'Arquivo ainda sendo baixado.', 404);
        }
        return $m;
    }

    /** A própria mensagem, se o usuário puder vê-la (reaproveita o filtro de messages()). */
    private function messagesAround(StaffMember $u, int $convId, int $messageId): array
    {
        return $this->messages($u, $convId, $messageId + 1, 1);
    }

    /**
     * Busca "o que chegou / o que saiu".
     * @param array{q?:string, direction?:string, from?:string, to?:string, sector_id?:int, contact?:string, user_id?:int} $f
     */
    public function search(StaffMember $u, array $f, int $limit = 100): array
    {
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $conds = [$where, "m.direction <> 'internal'"];
        // busca por número de protocolo (SAC26111600004, o antigo 20261115-00004 ou o chamado CH…):
        // acha a conversa pelo número, uma linha por conversa, sem procurar o texto nas mensagens
        $q = strtoupper(trim((string) ($f['q'] ?? '')));
        if (preg_match('/^(SAC\d{6,}|\d{8}-\d{5}|CH[0-9A-Z]{6,})$/', $q)) {
            $f['contact'] = $q;
            unset($f['q']);
            $conds[] = "m.id = (SELECT MIN(m1.id) FROM talk_messages m1 WHERE m1.conversation_id = c.id AND m1.direction <> 'internal')";
        }
        if (!empty($f['q'])) {
            $conds[] = 'MATCH(m.body) AGAINST (:q IN BOOLEAN MODE)';
            $params['q'] = $this->booleanQuery((string) $f['q']);
        }
        if (!empty($f['direction']) && in_array($f['direction'], ['in', 'out'], true)) {
            $conds[] = 'm.direction = :dir';
            $params['dir'] = $f['direction'];
        }
        if (!empty($f['from'])) {
            $conds[] = 'm.created_at >= :dfrom';
            $params['dfrom'] = $f['from'] . ' 00:00:00';
        }
        if (!empty($f['to'])) {
            $conds[] = 'm.created_at <= :dto';
            $params['dto'] = $f['to'] . ' 23:59:59';
        }
        if (!empty($f['sector_id'])) {
            $conds[] = 'c.sector_id = :fsector';
            $params['fsector'] = (int) $f['sector_id'];
        }
        if (!empty($f['user_id'])) {
            $conds[] = 'm.author_user_id = :fuser';
            $params['fuser'] = (int) $f['user_id'];
        }
        if (!empty($f['contact'])) {
            $conds[] = '(ct.name LIKE :cname OR ct.phone LIKE :cphone OR c.protocol = :proto OR c.legacy_protocol = :lproto OR c.helpdesk_protocol = :hproto)';
            $params['cname'] = '%' . $f['contact'] . '%';
            $params['cphone'] = '%' . preg_replace('/\D+/', '', (string) $f['contact']) . '%';
            $params['proto'] = $params['lproto'] = $params['hproto'] = $f['contact'];
        }
        return $this->db->all(
            'SELECT m.id, m.conversation_id, m.direction, m.body, m.created_at, m.author_user_id,
                    c.protocol, c.sector_id, ct.name AS contact_name, ct.phone
             FROM talk_messages m
             JOIN talk_conversations c ON c.id = m.conversation_id
             JOIN talk_contacts ct ON ct.id = c.contact_id
             WHERE ' . implode(' AND ', $conds) . '
             ORDER BY m.id DESC LIMIT ' . (int) $limit,
            $params
        );
    }

    /** "boleto atrasado" → "+boleto* +atrasado*" (todas as palavras, prefixo). */
    private function booleanQuery(string $q): string
    {
        $words = preg_split('/\s+/u', trim(preg_replace('/[+\-<>()~*"@]+/u', ' ', $q) ?? '')) ?: [];
        return implode(' ', array_map(fn ($w) => '+' . $w . '*', array_filter($words, fn ($w) => mb_strlen($w) >= 2)));
    }
}
