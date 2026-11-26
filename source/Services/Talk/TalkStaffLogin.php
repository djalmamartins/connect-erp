<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use Source\Models\User;
use Source\Services\Environments;
use Source\Services\Support\TicketError;
use Source\Support\Access;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Login único do Talk com o usuário do ERP (ADR 0359 e a ADR deste login).
 *
 * O ERP emite um código de uso único em GET /ambiente/talk (sessão completa: senha + 2FA) e manda o navegador
 * ao Talk em /ambiente/entrar/talk/{código}. O Talk, com o token de integração (escopo staff.login), troca o
 * código aqui por {id, name, email}. O código vale 60 s, uma vez, só para o ambiente talk.
 *
 * Só entra quem tem a permissão talk.access (papel ou liberação por usuário no cadastro do ERP).
 * O Talk pergunta de tempos em tempos em status() se o acesso continua valendo e encerra a sessão se não.
 * Qualquer recusa responde 404 genérico: não diz se o código existe ou de quem é.
 */
final class TalkStaffLogin
{
    public function __construct(private ?Environments $environments = null)
    {
    }

    /** @return array{id:int, name:string, email:string} */
    public function exchange(string $code): array
    {
        $code = strtolower(trim($code));
        $userId = preg_match('/^[a-f0-9]{64}$/', $code) ? ($this->environments ?? new Environments())->consume($code, 'talk') : null;
        $user = $userId !== null ? (new User())->findById($userId) : null;
        if (!$this->allowed($user)) {
            AppLogger::log('notice', 'Login do Talk recusado', ['event_type' => 'talk_staff_login_rejected', 'code' => 'TALK_LOGIN_INVALID', 'status' => 'resolved', 'users_id' => $userId], 'authentication');
            throw new TicketError('code', 'Código de acesso inválido ou expirado.', 404);
        }
        Audit::record('login', 'users', (int)$user->id, [], ['origin' => 'talk', 'action' => 'staff_login']);
        return $this->describe($user);
    }

    /** @return array{id:int, name:string, email:string, active:bool} */
    public function status(int $id): array
    {
        $user = $id > 0 ? (new User())->findById($id) : null;
        if ($user === null) {
            throw new TicketError('id', 'Usuário não encontrado.', 404);
        }
        return $this->describe($user) + ['active' => $this->allowed($user)];
    }

    /**
     * Quem pode usar o Talk hoje: funcionários com talk.access (papel ou liberação no cadastro), sem conta excluída.
     * A tela Equipe do Talk usa esta lista para trazer nome e e-mail do ERP; papel e setores ficam no Talk.
     *
     * @return list<array{id:int, name:string, email:string}>
     */
    public function staff(?\PDO $pdo = null): array
    {
        $pdo ??= \Source\Core\Connect::getInstance();
        // Candidatos: quem tem papel. Access::can decide (developer, permissão do papel, allow/deny do usuário).
        $rows = $pdo->query("SELECT DISTINCT u.id FROM users u JOIN access_user_roles ur ON ur.user_id = u.id
            WHERE u.status <> 'trash' ORDER BY u.id LIMIT 2000")->fetchAll(\PDO::FETCH_COLUMN);
        $out = [];
        foreach ($rows as $id) {
            $user = (new User())->findById((int)$id);
            if ($this->allowed($user)) {
                $out[] = $this->describe($user);
            }
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));
        return $out;
    }

    private function allowed(?User $user): bool
    {
        return $user !== null && (string)($user->status ?? '') !== 'trash' && Access::can('talk.access', $user);
    }

    /** @return array{id:int, name:string, email:string} */
    private function describe(User $user): array
    {
        return [
            'id' => (int)$user->id,
            'name' => trim($user->fullName()),
            'email' => mb_strtolower(trim((string)$user->email)),
        ];
    }
}
