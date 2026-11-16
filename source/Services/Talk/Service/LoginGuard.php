<?php
declare(strict_types=1);

namespace Source\Services\Talk\Service;

use Source\Services\Talk\Support\Db;

/**
 * Limite de tentativas de login: 5 erros no mesmo e-mail ou 20 no mesmo IP em 15 minutos bloqueiam
 * novas tentativas até a janela passar. Acerto limpa os erros daquele e-mail.
 */
final class LoginGuard
{
    public const WINDOW_MINUTES = 15;
    public const MAX_PER_EMAIL = 5;
    public const MAX_PER_IP = 20;

    public function __construct(private Db $db)
    {
    }

    public function blocked(string $email, string $ip): bool
    {
        $w = self::WINDOW_MINUTES;
        $byEmail = (int) $this->db->one("SELECT COUNT(*) n FROM talk_login_attempts WHERE email = ? AND created_at >= NOW() - INTERVAL $w MINUTE", [mb_strtolower($email)])['n'];
        $byIp = (int) $this->db->one("SELECT COUNT(*) n FROM talk_login_attempts WHERE ip = ? AND created_at >= NOW() - INTERVAL $w MINUTE", [$ip])['n'];
        return $byEmail >= self::MAX_PER_EMAIL || $byIp >= self::MAX_PER_IP;
    }

    public function failed(string $email, string $ip): void
    {
        $this->db->insert('talk_login_attempts', ['email' => mb_substr(mb_strtolower($email), 0, 150), 'ip' => mb_substr($ip, 0, 45)]);
        $this->db->run('DELETE FROM talk_login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
    }

    public function succeeded(string $email): void
    {
        $this->db->run('DELETE FROM talk_login_attempts WHERE email = ?', [mb_strtolower($email)]);
    }

    /**
     * Sessão ainda vale? Cai se o usuário foi desativado ou se a senha mudou depois do login.
     */
    public function sessionValid(int $userId, int $authAt): bool
    {
        $u = $this->db->one('SELECT active, UNIX_TIMESTAMP(password_changed_at) changed FROM talk_users WHERE id = ?', [$userId]);
        return $u && (int) $u['active'] === 1 && ($u['changed'] === null || (int) $u['changed'] <= $authAt);
    }
}
