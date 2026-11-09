<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Aceite do convite do morador (AP-152, ADR 0092), para a página pública /convite.
 * - preview(): convite pendente e válido → nome e e-mail mascarado; qualquer outro caso → null, sem
 *   dizer se o token não existe, venceu, foi usado ou revogado (GENERIC);
 * - accept(): numa transação, trava o convite (FOR UPDATE), confere o CPF/CNPJ (sempre igual ao do
 *   cadastro; ADR 0093: cadastro sem documento válido recusa), cria a conta comum (nível 1, papel "user" se existir), marca o convite como aceito
 *   (UPDATE condicionado) e liga o morador à conta. Qualquer falha desfaz tudo;
 * - não faz login: a pessoa entra depois pelo login normal, com o 2FA e a sessão do Studio;
 * - limite de tentativas por IP e por token (InviteThrottle, ADR 0093): falha fechado, sem a tabela recusa;
 * - o token nunca vai para log nem para a auditoria.
 */
final class ResidentInvitationAcceptance
{
    public const GENERIC = 'Este convite não é válido. Peça um novo convite à administração do condomínio.';
    public const MISMATCH = 'Os dados informados não conferem com o cadastro. Confira e tente de novo.';
    public const EXISTING = 'Já existe uma conta com este e-mail ou documento. Entre pelo login ou recupere a senha.';
    public const THROTTLED = 'Muitas tentativas. Aguarde alguns minutos e tente de novo.';
    public const THROTTLE_SCOPE = InviteThrottle::SCOPE;
    /** [tipo => [máximo de falhas, janela em segundos]] (ADR 0093: o limite mora em InviteThrottle). */
    public const LIMITS = InviteThrottle::LIMITS;

    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Problema da senha (regra pura), ou null. Valor com formato de hash é recusado. */
    public static function passwordProblem(string $password, string $repeat): ?string
    {
        $min = defined('CONF_PASSWD_MIN_LEN') ? (int)CONF_PASSWD_MIN_LEN : 8;
        $max = defined('CONF_PASSWD_MAX_LEN') ? (int)CONF_PASSWD_MAX_LEN : 40;
        if (!empty(password_get_info($password)['algo']) || mb_strlen($password) < $min || mb_strlen($password) > $max) {
            return "A senha deve ter entre {$min} e {$max} caracteres.";
        }
        if (!hash_equals($password, $repeat)) {
            return 'Você informou duas senhas diferentes.';
        }
        return null;
    }

    /** Nome e sobrenome (regra pura) a partir do nome do cadastro. */
    public static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/u', trim($name)) ?: [];
        $first = mb_substr((string)array_shift($parts), 0, 100);
        $last = mb_substr(trim(implode(' ', $parts)), 0, 100);
        return [$first === '' ? 'Morador' : $first, $last === '' ? ($first === '' ? 'Morador' : $first) : $last];
    }

    /** CPF/CNPJ informado confere com o cadastro (regra pura)? ADR 0093: cadastro sem documento válido nunca confere. */
    public static function documentMatches(?string $registered, string $typed): bool
    {
        return InvitationRules::documentMatches($registered, $typed);
    }

    /** Identificadores do limite (regra pura): hash do IP e hash do token, nunca o valor. */
    public static function subjects(?string $ip, string $token): array
    {
        return InviteThrottle::subjects($ip, $token);
    }

    /** @return ?array{invitation_id:int, name:string, email:string} */
    public function preview(mixed $token): ?array
    {
        $row = $this->find($token, false);
        return $row === null ? null : ['invitation_id' => (int)$row['invitation_id'], 'name' => (string)$row['name'],
            'email' => RegistryReports::maskEmail((string)$row['email'])];
    }

    /**
     * Aceita o convite e cria a conta. Não faz login.
     *
     * @return array{ok:bool, message:string, user_id?:int}
     */
    public function accept(mixed $token, string $document, string $password, string $repeat, ?string $ip = null): array
    {
        if (ResidentInvitations::hashOf($token) === null) {
            return ['ok' => false, 'message' => self::GENERIC];
        }
        // ADR 0093: o limite falha fechado; sem conseguir ler a tabela, o aceite é recusado.
        $throttle = new InviteThrottle($this->pdo);
        $subjects = InviteThrottle::subjects($ip, (string)$token);
        $state = $throttle->check($subjects);
        if ($state !== InviteThrottle::OK) {
            return ['ok' => false, 'message' => $state === InviteThrottle::BLOCKED ? self::THROTTLED : InviteThrottle::UNAVAILABLE_MESSAGE];
        }
        $problem = self::passwordProblem($password, $repeat);
        if ($problem !== null) {
            return ['ok' => false, 'message' => $problem];
        }
        $this->pdo->beginTransaction();
        try {
            $row = $this->find($token, true);
            // ADR 0093: morador que ficou sem documento válido depois do convite não aceita (mensagem genérica).
            if ($row === null || $row['user_id'] !== null || !InvitationRules::validDocument($row['document'])) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => $throttle->fail($subjects) ? self::GENERIC : InviteThrottle::UNAVAILABLE_MESSAGE];
            }
            if (!InvitationRules::documentMatches($row['document'], $document)) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => $throttle->fail($subjects) ? self::MISMATCH : InviteThrottle::UNAVAILABLE_MESSAGE];
            }
            $digits = preg_replace('/\D/', '', $document) ?? '';
            $exists = $this->pdo->prepare('SELECT COUNT(*) FROM users WHERE email = :e OR document = :d');
            $exists->execute(['e' => (string)$row['email'], 'd' => $digits]);
            if ((int)$exists->fetchColumn() > 0) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => self::EXISTING];
            }
            [$first, $last] = self::splitName((string)$row['name']);
            $user = (new User())->bootstrap($first, $last, (string)$row['email'], $digits, $password);
            $user->level = 1;
            $user->status = 'confirmed';
            if (!$user->save()) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => $user->message()?->getText() ?: self::GENERIC];
            }
            $userId = (int)$user->id;
            $accept = $this->pdo->prepare("UPDATE erp_resident_invitations SET status = 'accepted', accepted_at = NOW(), accepted_user_id = :u
                WHERE id = :id AND status = 'pending' AND expires_at > NOW()");
            $accept->execute(['u' => $userId, 'id' => (int)$row['invitation_id']]);
            $link = $this->pdo->prepare('UPDATE erp_residents SET user_id = :u WHERE id = :r AND user_id IS NULL');
            $link->execute(['u' => $userId, 'r' => (int)$row['resident_id']]);
            if ($accept->rowCount() !== 1 || $link->rowCount() !== 1) {
                $this->pdo->rollBack();
                return ['ok' => false, 'message' => self::GENERIC];
            }
            $this->pdo->prepare("INSERT IGNORE INTO access_user_roles (user_id, role_id) SELECT :u, id FROM access_roles WHERE slug = 'user'")->execute(['u' => $userId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            AppLogger::log('error', 'Falha no aceite do convite', ['event_type' => 'invite_accept_failed', 'error' => $error->getMessage()], 'authentication');
            return ['ok' => false, 'message' => self::GENERIC];
        }
        Audit::record('update', 'erp_resident_invitations', (int)$row['invitation_id'], ['status' => 'pending'], ['status' => 'accepted', 'user_id' => $userId]);
        Audit::record('create', 'users', $userId, null, ['origin' => 'resident_invitation', 'resident_id' => (int)$row['resident_id'], 'level' => 1]);
        return ['ok' => true, 'message' => 'Conta criada. Entre com o seu e-mail e a senha que você acabou de criar.', 'user_id' => $userId];
    }

    /** Convite pendente, válido, de morador ativo com e-mail. Formato inválido nem consulta o banco. */
    private function find(mixed $token, bool $lock): ?array
    {
        $hash = ResidentInvitations::hashOf($token);
        if ($hash === null) {
            return null;
        }
        $stmt = $this->pdo->prepare("SELECT i.id AS invitation_id, i.resident_id, r.name, r.email, r.document, r.user_id
            FROM erp_resident_invitations i JOIN erp_residents r ON r.id = i.resident_id
            WHERE i.token_hash = :h AND i.status = 'pending' AND i.expires_at > NOW() AND r.deleted_at IS NULL AND r.status = 'active'
              AND r.email IS NOT NULL AND r.email <> ''" . ($lock ? ' FOR UPDATE' : ''));
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
