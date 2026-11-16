<?php

declare(strict_types=1);

namespace Source\Services\Talk;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Support\AppLogger;
use Source\Support\Audit;

/**
 * Token de serviço do Connect Talk (ADR 0308). O Talk é um servidor, não um navegador: não tem sessão nem CSRF.
 * Ele manda "Authorization: Bearer ctk_<64 hex>" nas rotas /api/v1/talk, e só nelas.
 *
 * - O token é de um usuário comum de users (o "usuário de integração"). As permissões (support.manage) e o escopo
 *   de condomínios (CondominiumScope) desse usuário valem para tudo que o Talk faz. Nada de usuário paralelo.
 * - Só o sha256 fica no banco; o token aparece uma vez, no terminal (tools/talk-token.php). Não vence: é revogado
 *   à mão (ou deixa de valer se o usuário sair de "confirmed" ou perder a permissão).
 * - Falha de token conta no limite por IP (auth_throttle_events), e passou do limite = 429.
 */
final class TalkTokens
{
    public const CLIENT = 'talk';
    public const PREFIX = 'ctk_';
    public const PERMISSION = 'support.manage';
    public const THROTTLE_SCOPE = 'talk-token';
    /** Falhas por IP na janela (segundos). */
    public const THROTTLE = [20, 900];
    /** Atualiza last_used_at no máximo uma vez por este intervalo (segundos). */
    private const TOUCH_EVERY = 300;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Token novo (regra pura): ctk_ + 64 hexadecimais de random_bytes. */
    public static function newToken(): string
    {
        return self::PREFIX . bin2hex(random_bytes(32));
    }

    /** sha256 do token (regra pura). Formato inválido = null, sem consulta ao banco. */
    public static function hashOf(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^ctk_[0-9a-f]{64}$/', $token) === 1 ? hash('sha256', $token) : null;
    }

    /** Token do cabeçalho "Authorization: Bearer ..." (regra pura). Ausente ou malformado = null. */
    public static function bearerFrom(?string $header): ?string
    {
        if ($header === null || preg_match('/^\s*Bearer\s+(ctk_[0-9a-fA-F]{64})\s*$/', $header, $m) !== 1) {
            return null;
        }
        return strtolower($m[1]);
    }

    /** IP liberado pela lista opcional TALK_ALLOWED_IPS (regra pura). Lista vazia = qualquer IP. */
    public static function ipAllowed(?string $ip, string $list): bool
    {
        $allowed = array_values(array_filter(array_map('trim', explode(',', $list))));
        return $allowed === [] || ($ip !== null && in_array($ip, $allowed, true));
    }

    /**
     * Cria um token para o usuário de integração. Devolve o token em claro (só agora).
     *
     * @return array{id:int, token:string}
     */
    public function create(int $userId, string $name = 'Connect Talk', ?int $createdBy = null): array
    {
        $user = (new User())->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('Usuário não encontrado.');
        }
        $token = self::newToken();
        $this->pdo()->prepare('INSERT INTO erp_integration_tokens (client, name, user_id, token_hash, token_hint, created_by, created_at)
            VALUES (:client, :name, :user, :hash, :hint, :by, NOW())')->execute([
            'client' => self::CLIENT, 'name' => mb_substr(trim($name) ?: 'Connect Talk', 0, 80), 'user' => $userId,
            'hash' => hash('sha256', $token), 'hint' => substr($token, -4), 'by' => $createdBy,
        ]);
        $id = (int)$this->pdo()->lastInsertId();
        Audit::record('create', 'erp_integration_tokens', $id, [], ['client' => self::CLIENT, 'user_id' => $userId, 'token_hint' => substr($token, -4)]);
        return ['id' => $id, 'token' => $token];
    }

    /** Usuário do token, ou null (token desconhecido, revogado, usuário inativo ou sem a permissão). */
    public function authenticate(?string $token): ?User
    {
        $hash = self::hashOf($token);
        if ($hash === null) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id, user_id, last_used_at FROM erp_integration_tokens
            WHERE token_hash = :hash AND client = :client AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(['hash' => $hash, 'client' => self::CLIENT]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        $user = (new User())->findById((int)$row['user_id']);
        if (!$user || (string)$user->status !== 'confirmed' || !\Source\Support\Access::can(self::PERMISSION, $user)) {
            return null;
        }
        if ($row['last_used_at'] === null || strtotime((string)$row['last_used_at']) < time() - self::TOUCH_EVERY) {
            $this->pdo()->prepare('UPDATE erp_integration_tokens SET last_used_at = NOW() WHERE id = ?')->execute([(int)$row['id']]);
        }
        return $user;
    }

    /** Revoga o token pelo id. false = não havia token ativo com esse id. */
    public function revoke(int $id, string $reason = 'manual'): bool
    {
        $stmt = $this->pdo()->prepare('UPDATE erp_integration_tokens SET revoked_at = NOW(), revoke_reason = :reason WHERE id = :id AND revoked_at IS NULL');
        $stmt->execute(['reason' => mb_substr($reason, 0, 80), 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            return false;
        }
        Audit::record('update', 'erp_integration_tokens', $id, ['revoked_at' => null], ['revoked_at' => date('Y-m-d H:i:s'), 'revoke_reason' => $reason]);
        return true;
    }

    /** @return list<array{id:int, name:string, user_id:int, user:string, hint:string, created_at:string, last_used_at:?string, revoked_at:?string}> */
    public function list(): array
    {
        $stmt = $this->pdo()->prepare("SELECT t.id, t.name, t.user_id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS user, t.token_hint AS hint,
                t.created_at, t.last_used_at, t.revoked_at
            FROM erp_integration_tokens t JOIN users u ON u.id = t.user_id WHERE t.client = ? ORDER BY t.id DESC");
        $stmt->execute([self::CLIENT]);
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'user_id' => (int)$r['user_id'],
            'user' => (string)$r['user'], 'hint' => (string)$r['hint'], 'created_at' => (string)$r['created_at'],
            'last_used_at' => $r['last_used_at'] !== null ? (string)$r['last_used_at'] : null,
            'revoked_at' => $r['revoked_at'] !== null ? (string)$r['revoked_at'] : null], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** O IP passou do limite de falhas? Sem conseguir ler o limite, falha fechado (true). */
    public function blocked(?string $ip, ?int $now = null): bool
    {
        if ($ip === null) {
            return false;
        }
        [$max, $window] = self::THROTTLE;
        try {
            $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM auth_throttle_events WHERE scope = :s AND subject_hash = :h AND created_at > :since');
            $stmt->execute(['s' => self::THROTTLE_SCOPE, 'h' => LoginThrottle::hash('talk-ip:' . $ip), 'since' => ($now ?? time()) - $window]);
            return (int)$stmt->fetchColumn() >= $max;
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authorization', ['event_type' => 'talk_throttle_unavailable']);
            return true;
        }
    }

    /** Registra uma falha de token para o IP. */
    public function fail(?string $ip, ?int $now = null): void
    {
        if ($ip === null) {
            return;
        }
        try {
            $this->pdo()->prepare('INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES (:s, :h, :t)')
                ->execute(['s' => self::THROTTLE_SCOPE, 'h' => LoginThrottle::hash('talk-ip:' . $ip), 't' => $now ?? time()]);
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authorization', ['event_type' => 'talk_throttle_unavailable']);
        }
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
