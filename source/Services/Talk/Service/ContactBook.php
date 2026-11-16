<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;
use Source\Services\Talk\Support\Phone;

/**
 * Tela "Contatos": lista, busca, edição do nome e bloqueio.
 * - Supervisor vê todos; os demais, só quem já falou com os seus setores (LGPD: mínimo necessário).
 * - Editar o nome: quem vê o contato. Bloquear e desbloquear: supervisor e gerentes.
 * - Contato bloqueado: as mensagens dele são descartadas (só contadas) e as conversas abertas são
 *   encerradas sem aviso ao cliente.
 */
final class ContactBook
{
    public function __construct(private Db $db, private ConversationService $conversations)
    {
    }

    /** @return array{items:list<array>, total:int, page:int, per_page:int} */
    public function list(StaffMember $u, array $f): array
    {
        $per = 25;
        $page = max(1, (int) ($f['page'] ?? 1));
        [$conds, $params] = $this->scope($u);
        $q = trim((string) ($f['q'] ?? ''));
        if ($q !== '') {
            $digits = preg_replace('/\D+/', '', $q);
            $or = ['ct.name LIKE :q1', 'ct.erp_snapshot LIKE :q2'];
            $params['q1'] = $params['q2'] = '%' . addcslashes($q, '%_\\') . '%';
            if (strlen($digits) >= 4) {
                $or[] = 'ct.phone LIKE :d';
                $params['d'] = '%' . $digits . '%';
            }
            $conds[] = '(' . implode(' OR ', $or) . ')';
        }
        if (!empty($f['tag'])) {
            $conds[] = 'EXISTS (SELECT 1 FROM talk_contact_tags ft WHERE ft.contact_id = ct.id AND ft.tag_id = :tag)';
            $params['tag'] = (int) $f['tag'];
        }
        $status = (string) ($f['status'] ?? '');
        if ($status === 'blocked') {
            $conds[] = 'ct.blocked_at IS NOT NULL';
        } elseif ($status === 'erp') {
            $conds[] = 'ct.erp_person_id IS NOT NULL';
        } elseif ($status === 'unknown') {
            $conds[] = 'ct.erp_person_id IS NULL';
        }
        $where = implode(' AND ', $conds);
        $total = (int) $this->db->one("SELECT COUNT(*) n FROM talk_contacts ct WHERE $where", $params)['n'];
        $items = $this->db->all(
            "SELECT ct.id, ct.name, ct.phone, ct.erp_person_id, ct.erp_snapshot, ct.blocked_at, ct.block_reason, ct.blocked_messages, ct.created_at,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.contact_id = ct.id) AS conversations,
                    (SELECT MAX(c.last_message_at) FROM talk_conversations c WHERE c.contact_id = ct.id) AS last_contact_at,
                    (SELECT GROUP_CONCAT(tg.tag_id ORDER BY tg.tag_id) FROM talk_contact_tags tg WHERE tg.contact_id = ct.id) AS tag_ids
               FROM talk_contacts ct WHERE $where
              ORDER BY last_contact_at IS NULL, last_contact_at DESC, ct.id DESC
              LIMIT $per OFFSET " . (($page - 1) * $per),
            $params
        );
        return ['items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per];
    }

    /** Detalhe: dados, etiquetas, conversas (só as que o usuário pode ver) e notas. */
    public function show(StaffMember $u, int $id): array
    {
        $c = $this->find($u, $id);
        [$where, $params] = (new AccessPolicy())->sqlFilter($u, 'c');
        $c['history'] = $this->db->all(
            "SELECT c.id, c.protocol, c.status, c.opened_at, c.closed_at, s.name AS sector_name,
                    (SELECT LEFT(COALESCE(NULLIF(m.body, ''), '(arquivo)'), 90) FROM talk_messages m
                      WHERE m.conversation_id = c.id AND m.direction = 'in' ORDER BY m.id LIMIT 1) AS subject
               FROM talk_conversations c LEFT JOIN talk_sectors s ON s.id = c.sector_id
              WHERE c.contact_id = :contact AND $where ORDER BY c.id DESC LIMIT 20",
            $params + ['contact' => $id]
        );
        $c['tags'] = $this->db->all('SELECT t.id, t.name, t.color FROM talk_contact_tags ct JOIN talk_tags t ON t.id = ct.tag_id WHERE ct.contact_id = ? ORDER BY t.name', [$id]);
        $c['notes'] = $this->db->all(
            'SELECT n.id, n.body, n.created_at, u.name AS user_name FROM talk_contact_notes n LEFT JOIN talk_users u ON u.id = n.user_id
              WHERE n.contact_id = ? ORDER BY n.id DESC LIMIT 20', [$id]
        );
        $c['can_block'] = $this->canBlock($u);
        return $c;
    }

    public function rename(StaffMember $u, int $id, string $name): array
    {
        $this->find($u, $id);
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        if ($name === '' || mb_strlen($name) > 150) {
            throw new DomainError('Nome: de 1 a 150 caracteres.');
        }
        $this->db->run('UPDATE talk_contacts SET name = ? WHERE id = ?', [$name, $id]);
        return $this->find($u, $id);
    }

    public function block(StaffMember $u, int $id, ?string $reason): array
    {
        if (!$this->canBlock($u)) {
            throw new DomainError('Só o supervisor e os gerentes bloqueiam contatos.', 403);
        }
        $this->find($u, $id);
        $reason = trim((string) $reason);
        if (mb_strlen($reason) > 200) {
            throw new DomainError('Motivo: até 200 caracteres.');
        }
        $this->db->run('UPDATE talk_contacts SET blocked_at = NOW(), blocked_by = ?, block_reason = ? WHERE id = ? AND blocked_at IS NULL',
            [$u->userId, $reason !== '' ? $reason : null, $id]);
        // encerra o que estiver aberto, sem mensagem ao cliente (ação de gestão: vale para todos os setores)
        $actor = new StaffMember($u->userId, true);
        foreach ($this->db->all("SELECT id FROM talk_conversations WHERE contact_id = ? AND status <> 'closed'", [$id]) as $c) {
            $this->conversations->close((int) $c['id'], $actor, 'Contato bloqueado' . ($reason !== '' ? ": $reason" : '.'), false);
        }
        return $this->find($u, $id);
    }

    public function unblock(StaffMember $u, int $id): array
    {
        if (!$this->canBlock($u)) {
            throw new DomainError('Só o supervisor e os gerentes desbloqueiam contatos.', 403);
        }
        $this->find($u, $id);
        $this->db->run('UPDATE talk_contacts SET blocked_at = NULL, blocked_by = NULL, block_reason = NULL WHERE id = ?', [$id]);
        return $this->find($u, $id);
    }

    /** Usado na entrada: mensagem de contato bloqueado é descartada (e contada). */
    public static function dropIfBlocked(Db $db, string $phone): bool
    {
        $st = $db->run('UPDATE talk_contacts SET blocked_messages = blocked_messages + 1 WHERE phone = ? AND blocked_at IS NOT NULL', [Phone::normalize($phone)]);
        return $st->rowCount() > 0;
    }

    private function canBlock(StaffMember $u): bool
    {
        return $u->supervisor || $u->managerSectors !== [];
    }

    private function find(StaffMember $u, int $id): array
    {
        [$conds, $params] = $this->scope($u);
        $c = $this->db->one('SELECT ct.* FROM talk_contacts ct WHERE ct.id = :id AND ' . implode(' AND ', $conds), $params + ['id' => $id]);
        if (!$c) {
            throw new DomainError('Contato não encontrado.', 404);
        }
        return $c;
    }

    /** @return array{0:list<string>, 1:array} */
    private function scope(StaffMember $u): array
    {
        if ($u->supervisor) {
            return [['1=1'], []];
        }
        $sectors = array_map('intval', $u->sectors());
        $parts = ['c.assigned_user_id = :me'];
        if ($sectors) {
            $parts[] = 'c.sector_id IN (' . implode(',', $sectors) . ')';
        }
        return [['EXISTS (SELECT 1 FROM talk_conversations c WHERE c.contact_id = ct.id AND (' . implode(' OR ', $parts) . '))'], ['me' => $u->userId]];
    }
}
