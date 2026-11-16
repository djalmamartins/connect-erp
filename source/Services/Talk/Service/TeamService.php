<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Domain\StaffMember;
use Source\Services\Talk\Realtime\EventBus;
use Source\Services\Talk\Support\Db;

/**
 * Tela "Equipe e setores" (só supervisor): atendentes, papéis, setores de cada um, senha e ativação;
 * cadastro dos setores. Cada alteração fica no talk_audit_log (sem senhas).
 *
 * Papéis: agent (atende os setores marcados), manager (gerencia os setores marcados: vê tudo deles,
 * cuida das mensagens rápidas do setor) e supervisor (vê e gerencia tudo; não tem setor).
 */
final class TeamService
{
    public const MIN_PASSWORD = 8;
    private const ROLES = ['agent', 'manager', 'supervisor'];

    public function __construct(private Db $db, private EventBus $events, private ?string $ip = null)
    {
    }

    // ------------------------------------------------------------------ atendentes

    public function users(StaffMember $u): array
    {
        $this->assertSupervisor($u);
        $rows = $this->db->all(
            "SELECT u.id, u.name, u.email, u.active, u.erp_user_id, u.last_seen_at, u.created_at,
                    (u.last_seen_at IS NOT NULL AND u.last_seen_at >= NOW() - INTERVAL 60 SECOND) AS online,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.assigned_user_id = u.id AND c.status = 'open') AS open_count,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.assigned_user_id = u.id AND c.status = 'closed' AND c.closed_at >= CURDATE()) AS closed_today
               FROM talk_users u ORDER BY u.active DESC, u.name"
        );
        $staff = [];
        foreach ($this->db->all('SELECT user_id, sector_id, role FROM talk_staff') as $s) {
            $staff[(int) $s['user_id']][] = $s;
        }
        return array_map(function (array $r) use ($staff) {
            $rows = $staff[(int) $r['id']] ?? [];
            $roles = array_column($rows, 'role');
            $role = in_array('supervisor', $roles, true) ? 'supervisor' : (in_array('manager', $roles, true) ? 'manager' : 'agent');
            return [
                'id' => (int) $r['id'], 'name' => $r['name'], 'email' => $r['email'], 'active' => (bool) $r['active'],
                'erp_user_id' => $r['erp_user_id'] !== null ? (int) $r['erp_user_id'] : null,
                'role' => $role,
                'sector_ids' => array_values(array_map('intval', array_filter(array_column($rows, 'sector_id'), fn ($v) => $v !== null))),
                'online' => (bool) $r['online'], 'last_seen_at' => $r['last_seen_at'], 'created_at' => $r['created_at'],
                'open_count' => (int) $r['open_count'], 'closed_today' => (int) $r['closed_today'],
            ];
        }, $rows);
    }

    public function saveUser(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertSupervisor($u);
        $old = $id !== null ? $this->findUser($id) : null;
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
        $email = mb_strtolower(trim((string) ($in['email'] ?? '')));
        $role = (string) ($in['role'] ?? 'agent');
        $sectors = array_values(array_unique(array_map('intval', (array) ($in['sector_ids'] ?? []))));
        $password = (string) ($in['password'] ?? '');
        $erp = isset($in['erp_user_id']) && $in['erp_user_id'] !== '' && $in['erp_user_id'] !== null ? (int) $in['erp_user_id'] : null;

        if ($name === '' || mb_strlen($name) > 100) {
            throw new DomainError('Nome: obrigatório, até 100 caracteres.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 150) {
            throw new DomainError('E-mail inválido.');
        }
        if ($this->db->one('SELECT id FROM talk_users WHERE email = ? AND id <> ?', [$email, $id ?? 0])) {
            throw new DomainError('Já existe um atendente com este e-mail.');
        }
        if (!in_array($role, self::ROLES, true)) {
            throw new DomainError('Papel inválido.');
        }
        if ($role === 'supervisor') {
            $sectors = []; // supervisor vê tudo, sem setor
        } elseif (!$sectors) {
            throw new DomainError('Marque pelo menos um setor.');
        }
        foreach ($sectors as $sid) {
            if (!$this->db->one('SELECT id FROM talk_sectors WHERE id = ?', [$sid])) {
                throw new DomainError('Setor não encontrado.');
            }
        }
        if ($old === null || $password !== '') {
            $this->checkPassword($password);
        }
        if ($old !== null && $id === $u->userId && $role !== 'supervisor') {
            throw new DomainError('Você não pode tirar o seu próprio papel de supervisor.');
        }
        if ($old !== null && $role !== 'supervisor' && $this->isSupervisor($id) && $this->activeSupervisors() <= 1) {
            throw new DomainError('Precisa ficar pelo menos um supervisor ativo.');
        }

        $id = $this->db->transaction(function () use ($old, $id, $name, $email, $password, $erp, $role, $sectors): int {
            if ($old === null) {
                $id = $this->db->insert('talk_users', [
                    'name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'erp_user_id' => $erp,
                ]);
            } else {
                $this->db->run('UPDATE talk_users SET name = ?, email = ?, erp_user_id = ?, updated_at = NOW() WHERE id = ?', [$name, $email, $erp, $id]);
                if ($password !== '') {
                    // troca de senha pelo supervisor: as sessões abertas dessa pessoa caem
                    $this->db->run('UPDATE talk_users SET password_hash = ?, password_changed_at = NOW() WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $id]);
                }
                $this->db->run('DELETE FROM talk_staff WHERE user_id = ?', [$id]);
            }
            if ($role === 'supervisor') {
                $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => null, 'role' => 'supervisor']);
            }
            foreach ($sectors as $sid) {
                $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => $sid, 'role' => $role]);
            }
            return $id;
        });
        $this->audit($u, $old === null ? 'user.create' : 'user.update', 'user', $id, [
            'name' => $name, 'email' => $email, 'role' => $role, 'sector_ids' => $sectors, 'password_changed' => $old === null || $password !== '',
        ]);
        return $this->user($u, $id);
    }

    /**
     * Desativar: não entra mais, as sessões caem e os atendimentos em andamento voltam para a fila
     * do setor (com nota interna). O histórico continua com o nome dela.
     */
    public function setActive(StaffMember $u, int $id, bool $active): array
    {
        $this->assertSupervisor($u);
        $this->findUser($id);
        if (!$active && $id === $u->userId) {
            throw new DomainError('Você não pode desativar a si mesmo.');
        }
        if (!$active && $this->isSupervisor($id) && $this->activeSupervisors() <= 1) {
            throw new DomainError('Precisa ficar pelo menos um supervisor ativo.');
        }
        $returned = 0;
        $this->db->transaction(function () use ($id, $active, $u, &$returned): void {
            $this->db->run('UPDATE talk_users SET active = ?, updated_at = NOW() WHERE id = ?', [(int) $active, $id]);
            if ($active) {
                return;
            }
            $convs = $this->db->all("SELECT id, sector_id FROM talk_conversations WHERE assigned_user_id = ? AND status = 'open' FOR UPDATE", [$id]);
            foreach ($convs as $c) {
                $this->db->run("UPDATE talk_conversations SET status = 'pending', assigned_user_id = NULL, queued_at = NOW() WHERE id = ?", [$c['id']]);
                $this->db->insert('talk_messages', [
                    'conversation_id' => $c['id'], 'direction' => 'internal', 'author_user_id' => $u->userId, 'type' => 'note',
                    'body' => 'Atendimento voltou para a fila: o atendente foi desativado.', 'ack' => 'received',
                ]);
                $this->events->publish('conversation.updated', (int) $c['id'], $c['sector_id'] !== null ? (int) $c['sector_id'] : null, $u->userId, ['id' => (int) $c['id'], 'status' => 'pending']);
                $returned++;
            }
        });
        $this->audit($u, $active ? 'user.activate' : 'user.deactivate', 'user', $id, $active ? [] : ['returned_to_queue' => $returned]);
        return $this->user($u, $id) + ['returned_to_queue' => $returned];
    }

    /** Qualquer pessoa troca a própria senha, confirmando a atual. */
    public function changeOwnPassword(int $userId, string $current, string $new): void
    {
        $row = $this->findUser($userId);
        if (!password_verify($current, $row['password_hash'])) {
            throw new DomainError('Senha atual incorreta.');
        }
        if (hash_equals($current, $new)) {
            throw new DomainError('A nova senha precisa ser diferente da atual.');
        }
        $this->checkPassword($new);
        $this->db->run('UPDATE talk_users SET password_hash = ?, password_changed_at = NOW(), updated_at = NOW() WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $userId]);
        $this->audit(new StaffMember($userId), 'user.password', 'user', $userId, []);
    }

    public function user(StaffMember $u, int $id): array
    {
        foreach ($this->users($u) as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }
        throw new DomainError('Atendente não encontrado.', 404);
    }

    // ------------------------------------------------------------------ setores

    public function sectors(StaffMember $u): array
    {
        $this->assertSupervisor($u);
        $rows = $this->db->all(
            "SELECT s.id, s.name, s.jack_key, s.color, s.description, s.active, s.menu_key,
                    (SELECT COUNT(DISTINCT st.user_id) FROM talk_staff st JOIN talk_users tu ON tu.id = st.user_id AND tu.active = 1 WHERE st.sector_id = s.id) AS people,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.sector_id = s.id AND c.status = 'pending') AS waiting,
                    (SELECT COUNT(*) FROM talk_conversations c WHERE c.sector_id = s.id AND c.status = 'open') AS open_count,
                    (SELECT COUNT(*) FROM talk_channels ch WHERE ch.sector_id = s.id AND ch.status = 'active') AS channels
               FROM talk_sectors s ORDER BY s.active DESC, s.menu_key + 0, s.name"
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['id'], 'name' => $r['name'], 'jack' => $r['jack_key'] !== null, 'color' => $r['color'],
            'description' => $r['description'], 'active' => (bool) $r['active'],
            'people' => (int) $r['people'], 'waiting' => (int) $r['waiting'], 'open_count' => (int) $r['open_count'], 'channels' => (int) $r['channels'],
        ], $rows);
    }

    public function saveSector(StaffMember $u, array $in, ?int $id = null): array
    {
        $this->assertSupervisor($u);
        $old = $id !== null ? $this->findSector($id) : null;
        $name = trim(preg_replace('/\s+/', ' ', (string) ($in['name'] ?? '')));
        $color = strtoupper((string) ($in['color'] ?? $old['color'] ?? '#12804A'));
        $desc = trim((string) ($in['description'] ?? '')) ?: null;
        if ($name === '' || mb_strlen($name) > 80) {
            throw new DomainError('Nome do setor: obrigatório, até 80 caracteres.');
        }
        if (!preg_match('/^#[0-9A-F]{6}$/', $color)) {
            throw new DomainError('Cor inválida.');
        }
        if ($desc !== null && mb_strlen($desc) > 255) {
            throw new DomainError('Descrição: até 255 caracteres.');
        }
        if ($this->db->one('SELECT id FROM talk_sectors WHERE name = ? AND id <> ?', [$name, $id ?? 0])) {
            throw new DomainError('Já existe um setor com este nome.');
        }
        if ($old === null) {
            $next = (int) ($this->db->one('SELECT MAX(menu_key + 0) n FROM talk_sectors')['n'] ?? 0) + 1;
            $id = $this->db->insert('talk_sectors', ['name' => $name, 'menu_key' => (string) $next, 'color' => $color, 'description' => $desc]);
        } else {
            $this->db->run('UPDATE talk_sectors SET name = ?, color = ?, description = ? WHERE id = ?', [$name, $color, $desc, $id]);
        }
        $this->audit($u, $old === null ? 'sector.create' : 'sector.update', 'sector', $id, ['name' => $name, 'color' => $color]);
        return $this->sector($u, $id);
    }

    /** Desativar setor: só sem ninguém esperando ou em atendimento, e nunca um dos três da Jack. */
    public function setSectorActive(StaffMember $u, int $id, bool $active): array
    {
        $this->assertSupervisor($u);
        $s = $this->findSector($id);
        if (!$active) {
            if ($s['jack_key'] !== null) {
                throw new DomainError('A Jack encaminha para este setor: ele não pode ser desativado (pode ser renomeado).');
            }
            if ($this->db->one("SELECT id FROM talk_conversations WHERE sector_id = ? AND status IN ('bot','pending','open') LIMIT 1", [$id])) {
                throw new DomainError('Ainda há atendimentos neste setor: transfira ou encerre antes de desativar.');
            }
        }
        $this->db->run('UPDATE talk_sectors SET active = ? WHERE id = ?', [(int) $active, $id]);
        if (!$active) {
            $this->db->run('UPDATE talk_channels SET sector_id = NULL WHERE sector_id = ?', [$id]); // número volta para a Jack
        }
        $this->audit($u, $active ? 'sector.activate' : 'sector.deactivate', 'sector', $id, []);
        return $this->sector($u, $id);
    }

    public function sector(StaffMember $u, int $id): array
    {
        foreach ($this->sectors($u) as $s) {
            if ($s['id'] === $id) {
                return $s;
            }
        }
        throw new DomainError('Setor não encontrado.', 404);
    }

    public function auditLog(StaffMember $u, int $limit = 50): array
    {
        $this->assertSupervisor($u);
        return $this->db->all(
            'SELECT a.id, a.action, a.target_type, a.target_id, a.details, a.created_at, u.name AS user_name
               FROM talk_audit_log a LEFT JOIN talk_users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    // ------------------------------------------------------------------ apoio

    private function checkPassword(string $p): void
    {
        if (mb_strlen($p) < self::MIN_PASSWORD || mb_strlen($p) > 72) {
            throw new DomainError('Senha: de ' . self::MIN_PASSWORD . ' a 72 caracteres.');
        }
        if (!preg_match('/\p{L}/u', $p) || !preg_match('/\d/', $p)) {
            throw new DomainError('Senha: use letras e números.');
        }
    }

    private function isSupervisor(int $userId): bool
    {
        return (bool) $this->db->one("SELECT id FROM talk_staff WHERE user_id = ? AND role = 'supervisor'", [$userId]);
    }

    private function activeSupervisors(): int
    {
        return (int) $this->db->one(
            "SELECT COUNT(DISTINCT s.user_id) n FROM talk_staff s JOIN talk_users u ON u.id = s.user_id WHERE s.role = 'supervisor' AND u.active = 1"
        )['n'];
    }

    private function findUser(int $id): array
    {
        $row = $this->db->one('SELECT * FROM talk_users WHERE id = ?', [$id]);
        if (!$row) {
            throw new DomainError('Atendente não encontrado.', 404);
        }
        return $row;
    }

    private function findSector(int $id): array
    {
        $row = $this->db->one('SELECT * FROM talk_sectors WHERE id = ?', [$id]);
        if (!$row) {
            throw new DomainError('Setor não encontrado.', 404);
        }
        return $row;
    }

    private function audit(StaffMember $u, string $action, string $type, int $id, array $details): void
    {
        $this->db->insert('talk_audit_log', [
            'user_id' => $u->userId, 'action' => $action, 'target_type' => $type, 'target_id' => $id,
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, 'ip' => $this->ip,
        ]);
    }

    private function assertSupervisor(StaffMember $u): void
    {
        if (!$u->supervisor) {
            throw new DomainError('Só o supervisor cadastra atendentes e setores.', 403);
        }
    }
}
