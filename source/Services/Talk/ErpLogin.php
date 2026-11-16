<?php
declare(strict_types=1);

namespace Source\Services\Talk;

use Source\Models\User;
use Source\Services\Talk\Support\Db;
use Source\Support\Access;

/**
 * Entrada no Talk com o login do ERP (ADR 0324). Quem entra é o usuário do ERP ativo e com a permissão talk.access; o
 * Talk não tem senha nem tela de login próprias. O que é do Talk (setores e papel: atendente, gestor, supervisor)
 * continua em talk_users/talk_staff, e o perfil do Talk se liga ao usuário do ERP por talk_users.erp_user_id:
 *
 * 1. perfil com esse erp_user_id;
 * 2. senão, perfil do Talk separado com o mesmo e-mail (equipe que já existia), que passa a ter o erp_user_id;
 * 3. senão, perfil novo. O primeiro da equipe vira supervisor (alguém precisa montar os setores); os demais entram
 *    sem setor e o supervisor escolhe os setores em Equipe.
 *
 * Perfil desativado no Talk (Equipe) não entra, mesmo com a permissão no ERP.
 */
final class ErpLogin
{
    public const PERMISSION = 'talk.access';

    public function __construct(private Db $db)
    {
    }

    /** @return array{id:int, name:string}|null */
    public function profileFor(?User $user): ?array
    {
        if (!$this->allowed($user)) {
            return null;
        }
        $erpId = (int) $user->id;
        $name = mb_substr(trim($user->fullName()) ?: (string) $user->email, 0, 100);
        $row = $this->db->one('SELECT id, name, active FROM talk_users WHERE erp_user_id = ? ORDER BY id LIMIT 1', [$erpId]);
        if ($row === null) {
            $email = mb_strtolower(trim((string) $user->email));
            $row = $email !== '' ? $this->db->one('SELECT id, name, active, erp_user_id FROM talk_users WHERE email = ? LIMIT 1', [$email]) : null;
            if ($row !== null && $row['erp_user_id'] !== null && (int) $row['erp_user_id'] !== $erpId) {
                return null; // e-mail de outro usuário do ERP: não mistura perfis
            }
            if ($row !== null) {
                $this->db->run('UPDATE talk_users SET erp_user_id = ?, updated_at = NOW() WHERE id = ?', [$erpId, (int) $row['id']]);
            } else {
                $row = $this->create($erpId, $name, $email);
            }
        }
        if ((int) $row['active'] !== 1) {
            return null;
        }
        if ($row['name'] !== $name) {
            $this->db->run('UPDATE talk_users SET name = ?, updated_at = NOW() WHERE id = ?', [$name, (int) $row['id']]);
        }
        $this->db->run('UPDATE talk_users SET last_seen_at = NOW() WHERE id = ?', [(int) $row['id']]);
        return ['id' => (int) $row['id'], 'name' => $name];
    }

    /** Por que este usuário do ERP não entra no Talk (texto para a tela). */
    public function deniedReason(?User $user): string
    {
        if ($user === null) {
            return 'Entre no Connect ERP para abrir o Talk.';
        }
        if (!$this->allowed($user)) {
            return 'Seu usuário do ERP não tem a permissão "Atendimento por WhatsApp (Connect Talk)". Peça ao administrador.';
        }
        return 'Seu acesso ao Talk está desativado em Equipe. Fale com o supervisor do Talk.';
    }

    private function allowed(?User $user): bool
    {
        return $user !== null && !empty($user->id) && (string) $user->status === 'confirmed' && Access::can(self::PERMISSION, $user);
    }

    /** @return array{id:int, name:string, active:int} */
    private function create(int $erpId, string $name, string $email): array
    {
        return $this->db->transaction(function () use ($erpId, $name, $email): array {
            $first = (int) $this->db->one("SELECT COUNT(*) n FROM talk_staff s JOIN talk_users u ON u.id = s.user_id
                WHERE s.role = 'supervisor' AND u.active = 1")['n'] === 0;
            $id = $this->db->insert('talk_users', [
                'name' => $name,
                // sem senha no Talk: o hash é de um valor aleatório que ninguém conhece (a coluna é obrigatória)
                'email' => $email !== '' ? $email : 'erp-' . $erpId . '@talk.invalid',
                'password_hash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT),
                'erp_user_id' => $erpId,
            ]);
            if ($first) {
                $this->db->insert('talk_staff', ['user_id' => $id, 'sector_id' => null, 'role' => 'supervisor']);
            }
            return ['id' => $id, 'name' => $name, 'active' => 1];
        });
    }
}
