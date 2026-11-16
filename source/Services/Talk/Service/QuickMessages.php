<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Support\Db;

/**
 * Mensagens rápidas (respostas prontas).
 * - Gerais (sem setor): todo mundo usa; só o supervisor cria/edita.
 * - Do setor: quem atende ou gerencia o setor usa; gerente do setor e supervisor criam/editam.
 * Campos {nome}, {primeiro_nome}, {protocolo}, {setor} e {atendente} são trocados na tela, ao inserir.
 */
final class QuickMessages
{
    public function __construct(private Db $db)
    {
    }

    /** O que o atendente pode usar (gerais + dos setores dele). */
    public function available(StaffMember $u): array
    {
        $sql = 'SELECT q.id, q.shortcut, q.body, q.sector_id, s.name sector_name FROM talk_quick_messages q
                LEFT JOIN talk_sectors s ON s.id = q.sector_id';
        if (!$u->supervisor) {
            $sectors = array_map('intval', $u->sectors());
            $sql .= ' WHERE q.sector_id IS NULL' . ($sectors ? ' OR q.sector_id IN (' . implode(',', $sectors) . ')' : '');
        }
        $rows = $this->db->all($sql . ' ORDER BY q.shortcut');
        foreach ($rows as &$r) {
            $r['id'] = (int) $r['id'];
            $r['sector_id'] = $r['sector_id'] !== null ? (int) $r['sector_id'] : null;
            $r['can_edit'] = $this->canManage($u, $r['sector_id']);
        }
        return $rows;
    }

    public function canManage(StaffMember $u, ?int $sectorId): bool
    {
        return $u->supervisor || ($sectorId !== null && in_array($sectorId, $u->managerSectors, true));
    }

    public function save(StaffMember $u, array $in, ?int $id = null): array
    {
        $shortcut = strtolower(trim(ltrim((string) ($in['shortcut'] ?? ''), '/')));
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $sector = isset($in['sector_id']) && $in['sector_id'] !== '' && $in['sector_id'] !== null ? (int) $in['sector_id'] : null;

        if (!preg_match('/^[a-z0-9][a-z0-9_-]{1,39}$/', $shortcut)) {
            throw new DomainError('Atalho: de 2 a 40 letras minúsculas, números, "-" ou "_", sem espaço.');
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            throw new DomainError('Mensagem: obrigatória, até 2000 caracteres.');
        }
        if ($sector !== null && !$this->db->one('SELECT id FROM talk_sectors WHERE id = ?', [$sector])) {
            throw new DomainError('Setor não encontrado.');
        }
        if (!$this->canManage($u, $sector)) {
            throw new DomainError($sector === null
                ? 'Só o supervisor cria mensagens gerais.'
                : 'Só o gerente do setor ou o supervisor cadastram mensagens deste setor.', 403);
        }
        if ($id !== null) {
            $this->assertManage($u, $id);
        }
        $dup = $this->db->one('SELECT id FROM talk_quick_messages WHERE shortcut = ? AND id <> ?', [$shortcut, $id ?? 0]);
        if ($dup) {
            throw new DomainError("Já existe a mensagem /$shortcut.");
        }
        if ($id === null) {
            $id = $this->db->insert('talk_quick_messages', ['shortcut' => $shortcut, 'body' => $body, 'sector_id' => $sector, 'created_by' => $u->userId]);
        } else {
            $this->db->run('UPDATE talk_quick_messages SET shortcut = ?, body = ?, sector_id = ? WHERE id = ?', [$shortcut, $body, $sector, $id]);
        }
        return ['id' => $id, 'shortcut' => $shortcut, 'body' => $body, 'sector_id' => $sector];
    }

    public function delete(StaffMember $u, int $id): void
    {
        $this->assertManage($u, $id);
        $this->db->run('DELETE FROM talk_quick_messages WHERE id = ?', [$id]);
    }

    private function assertManage(StaffMember $u, int $id): void
    {
        $row = $this->db->one('SELECT sector_id FROM talk_quick_messages WHERE id = ?', [$id]);
        if (!$row) {
            throw new DomainError('Mensagem rápida não encontrada.', 404);
        }
        if (!$this->canManage($u, $row['sector_id'] !== null ? (int) $row['sector_id'] : null)) {
            throw new DomainError('Sem permissão para alterar esta mensagem.', 403);
        }
    }
}
