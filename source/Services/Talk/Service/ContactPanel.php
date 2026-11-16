<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Support\Db;

/**
 * Painel do contato ao lado da conversa: dados do ERP, etiquetas, últimos chamados e notas.
 * Tudo é acessado pela conversa aberta: quem vê a conversa vê e anota o contato dela.
 * Etiquetas: o supervisor e os gerentes cadastram; quem vê a conversa aplica ou tira.
 */
final class ContactPanel
{
    public const COLORS = ['#12804A', '#C9A227', '#2F6FDE', '#C2410C', '#B42318', '#7A3EC8', '#0E7490', '#5F6B66'];

    public function __construct(private Db $db, private AccessPolicy $policy, private ?EventBus $events = null)
    {
    }

    public function panel(StaffMember $u, int $convId): array
    {
        $conv = $this->conversation($u, $convId);
        $contactId = (int) $conv['contact_id'];
        $h = $this->historyPage($u, $conv, 1);
        return [
            'tags' => $this->contactTags($contactId),
            'history' => $h['items'],
            'history_page' => $h['page'],
            'history_pages' => $h['pages'],
            'history_total' => $h['total'],
            'history_hidden' => $h['hidden'], // de outros setores, que esta pessoa não vê
            'notes' => $this->db->all(
                'SELECT n.id, n.body, n.created_at, n.user_id, u.name AS user_name FROM talk_contact_notes n
                 LEFT JOIN talk_users u ON u.id = n.user_id WHERE n.contact_id = ? ORDER BY n.id DESC LIMIT 20',
                [$contactId]
            ),
        ];
    }

    public const HISTORY_PER_PAGE = 5;

    /** Atendimentos da pessoa (inclusive o atual), do protocolo mais novo para o mais antigo, 5 por página. */
    public function history(StaffMember $u, int $convId, int $page): array
    {
        return $this->historyPage($u, $this->conversation($u, $convId), $page);
    }

    private function historyPage(StaffMember $u, array $conv, int $page): array
    {
        $contactId = (int) $conv['contact_id'];
        [$where, $params] = $this->policy->sqlFilter($u, 'c');
        $params['contact'] = $contactId;
        $total = (int) $this->db->one("SELECT COUNT(*) n FROM talk_conversations c WHERE c.contact_id = :contact AND $where", $params)['n'];
        $all = (int) $this->db->one('SELECT COUNT(*) n FROM talk_conversations WHERE contact_id = ?', [$contactId])['n'];
        $pages = max(1, (int) ceil($total / self::HISTORY_PER_PAGE));
        $page = min(max(1, $page), $pages);
        $items = $this->db->all(
            "SELECT c.id, c.protocol, c.helpdesk_protocol, c.status, c.opened_at AS created_at, c.closed_at, s.name AS sector_name,
                    (SELECT LEFT(COALESCE(NULLIF(m.body, ''), '(arquivo)'), 80) FROM talk_messages m
                      WHERE m.conversation_id = c.id AND m.direction = 'in' ORDER BY m.id LIMIT 1) AS subject
               FROM talk_conversations c LEFT JOIN talk_sectors s ON s.id = c.sector_id
              WHERE c.contact_id = :contact AND $where
              ORDER BY c.id DESC LIMIT " . self::HISTORY_PER_PAGE . ' OFFSET ' . (($page - 1) * self::HISTORY_PER_PAGE),
            $params
        );
        foreach ($items as &$it) {
            $it['id'] = (int) $it['id'];
            $it['current'] = $it['id'] === (int) $conv['id'];
        }
        return ['items' => $items, 'page' => $page, 'pages' => $pages, 'total' => $total, 'hidden' => $all - $total];
    }

    // ------------------------------------------------------------ etiquetas

    public function tags(): array
    {
        return array_map(fn ($t) => ['id' => (int) $t['id']] + $t, $this->db->all(
            'SELECT t.id, t.name, t.color, (SELECT COUNT(*) FROM talk_contact_tags ct WHERE ct.tag_id = t.id) AS contacts
               FROM talk_tags t ORDER BY t.name'
        ));
    }

    public function saveTag(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertManager($u);
        $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? '');
        $color = strtoupper((string) ($in['color'] ?? self::COLORS[0]));
        if ($name === '' || mb_strlen($name) > 40) {
            throw new DomainError('Nome da etiqueta: de 1 a 40 caracteres.');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new DomainError('Cor inválida.');
        }
        if ($id !== null && !$this->db->one('SELECT id FROM talk_tags WHERE id = ?', [$id])) {
            throw new DomainError('Etiqueta não encontrada.', 404);
        }
        if ($this->db->one('SELECT id FROM talk_tags WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            throw new DomainError("Já existe a etiqueta \"$name\".");
        }
        if ($id === null) {
            $id = $this->db->insert('talk_tags', ['name' => $name, 'color' => $color, 'created_by' => $u->userId]);
        } else {
            $this->db->run('UPDATE talk_tags SET name = ?, color = ? WHERE id = ?', [$name, $color, $id]);
        }
        return ['id' => $id, 'name' => $name, 'color' => $color];
    }

    public function deleteTag(StaffMember $u, int $id): void
    {
        $this->assertManager($u);
        $this->db->run('DELETE FROM talk_tags WHERE id = ?', [$id]);
    }

    /** Troca as etiquetas do contato da conversa pela lista enviada. */
    public function setContactTags(StaffMember $u, int $convId, array $tagIds): array
    {
        $conv = $this->conversation($u, $convId);
        $contactId = (int) $conv['contact_id'];
        $tagIds = array_values(array_unique(array_map('intval', $tagIds)));
        if (count($tagIds) > 10) {
            throw new DomainError('No máximo 10 etiquetas por contato.');
        }
        if ($tagIds) {
            $found = $this->db->all('SELECT id FROM talk_tags WHERE id IN (' . implode(',', $tagIds) . ')');
            if (count($found) !== count($tagIds)) {
                throw new DomainError('Etiqueta não encontrada.', 404);
            }
        }
        $this->db->transaction(function () use ($contactId, $tagIds, $u) {
            $this->db->run('DELETE FROM talk_contact_tags WHERE contact_id = ?' . ($tagIds ? ' AND tag_id NOT IN (' . implode(',', $tagIds) . ')' : ''), [$contactId]);
            foreach ($tagIds as $t) {
                $this->db->run('INSERT IGNORE INTO talk_contact_tags (contact_id, tag_id, created_by) VALUES (?, ?, ?)', [$contactId, $t, $u->userId]);
            }
        });
        $tags = $this->contactTags($contactId);
        $this->events?->publish('conversation.updated', $convId, $conv['sector_id'] !== null ? (int) $conv['sector_id'] : null, $u->userId, ['tags' => array_column($tags, 'id')]);
        return $tags;
    }

    // ------------------------------------------------------------ notas do contato

    public function addNote(StaffMember $u, int $convId, string $body): array
    {
        $conv = $this->conversation($u, $convId);
        $body = trim(str_replace("\r\n", "\n", $body));
        if ($body === '' || mb_strlen($body) > 1000) {
            throw new DomainError('Nota: de 1 a 1000 caracteres.');
        }
        $id = $this->db->insert('talk_contact_notes', ['contact_id' => $conv['contact_id'], 'user_id' => $u->userId, 'body' => $body]);
        return ['id' => $id, 'body' => $body];
    }

    /** Só o autor, o supervisor ou um gerente apaga a nota. */
    public function deleteNote(StaffMember $u, int $convId, int $noteId): void
    {
        $conv = $this->conversation($u, $convId);
        $n = $this->db->one('SELECT user_id FROM talk_contact_notes WHERE id = ? AND contact_id = ?', [$noteId, $conv['contact_id']]);
        if (!$n) {
            throw new DomainError('Nota não encontrada.', 404);
        }
        if ((int) $n['user_id'] !== $u->userId && !$u->supervisor && $u->managerSectors === []) {
            throw new DomainError('Só quem escreveu a nota pode apagá-la.', 403);
        }
        $this->db->run('DELETE FROM talk_contact_notes WHERE id = ?', [$noteId]);
    }

    private function contactTags(int $contactId): array
    {
        return $this->db->all(
            'SELECT t.id, t.name, t.color FROM talk_contact_tags ct JOIN talk_tags t ON t.id = ct.tag_id WHERE ct.contact_id = ? ORDER BY t.name',
            [$contactId]
        );
    }

    private function conversation(StaffMember $u, int $convId): array
    {
        $conv = $this->db->one('SELECT * FROM talk_conversations WHERE id = ?', [$convId]);
        if (!$conv || !$this->policy->canView($u, $conv)) {
            throw new DomainError('Sem acesso a esta conversa.', 403);
        }
        return $conv;
    }

    private function assertManager(StaffMember $u): void
    {
        if (!$u->supervisor && $u->managerSectors === []) {
            throw new DomainError('Só o supervisor e os gerentes cadastram etiquetas.', 403);
        }
    }
}
