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
 *
 * ADR 0623: o token também é criado e revogado na tela Integrações, com escopos (o que ele pode fazer), validade
 * opcional, IPs por token e limite de RATE pedidos por minuto por token. Cada rota /talk pede o seu escopo
 * (allows()); o token criado antes disso ficou com LEGACY_SCOPES, o que o Talk já usava.
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
    /** Pedidos por janela (segundos), por token. */
    public const RATE = [120, 60];

    /** Escopo => [rótulo na tela, read|write]. Ordem = ordem na tela. */
    public const SCOPES = [
        'condominiums.read' => ['Condomínios, unidades, áreas comuns e comunicados', 'read'],
        'people.read' => ['Achar a pessoa pelo telefone', 'read'],
        'residents.read' => ['Dados do morador identificado (chamados, reservas, encomendas, assembleias, documentos)', 'read'],
        'charges.read' => ['2ª via do boleto', 'read'],
        'tickets.write' => ['Abrir, responder, transferir e encerrar chamados', 'write'],
    ];
    /** O que o Talk já fazia antes dos escopos (tokens criados pelo terminal e o token que já existia). */
    public const LEGACY_SCOPES = ['people.read', 'charges.read', 'tickets.write'];

    /** Token da requisição atual (posto pelo TalkApiAuthenticated). */
    private static ?array $current = null;

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

    /** Escopos válidos, sem repetição, na ordem da tela (regra pura). Nenhum válido = erro. @return list<string> */
    public static function normalizeScopes(mixed $scopes): array
    {
        $list = is_string($scopes) ? explode(',', $scopes) : (is_array($scopes) ? $scopes : []);
        $wanted = array_map(static fn (mixed $s): string => is_string($s) ? trim($s) : '', $list);
        $out = array_values(array_filter(array_keys(self::SCOPES), static fn (string $s): bool => in_array($s, $wanted, true)));
        if ($out === []) {
            throw new \InvalidArgumentException('Escolha pelo menos um escopo.');
        }
        return $out;
    }

    /** Lista de IPs separada por vírgula, conferida (regra pura). Vazio = null (qualquer IP). */
    public static function normalizeIps(mixed $ips): ?string
    {
        $list = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,;]+/', is_string($ips) ? $ips : '') ?: []))));
        foreach ($list as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new \InvalidArgumentException('IP inválido: ' . mb_substr($ip, 0, 45) . '.');
            }
        }
        if ($list === []) {
            return null;
        }
        $joined = implode(',', $list);
        if (strlen($joined) > 255) {
            throw new \InvalidArgumentException('Lista de IPs grande demais.');
        }
        return $joined;
    }

    /** O token pode ser usado agora? Revogado, vencido ou de IP fora da lista = não (regra pura). */
    public static function usable(array $row, ?string $ip, ?int $now = null): bool
    {
        if (($row['revoked_at'] ?? null) !== null) {
            return false;
        }
        $expires = $row['expires_at'] ?? null;
        if ($expires !== null && strtotime((string)$expires) <= ($now ?? time())) {
            return false;
        }
        return self::ipAllowed($ip, (string)($row['allowed_ips'] ?? ''));
    }

    /**
     * Cria um token para o usuário de integração. Devolve o token em claro (só agora).
     * Sem escopos (terminal) = LEGACY_SCOPES, o que o Talk sempre usou.
     *
     * @param list<string>|null $scopes
     * @return array{id:int, token:string}
     */
    public function create(int $userId, string $name = 'Connect Talk', ?int $createdBy = null, ?array $scopes = null,
        ?string $expiresAt = null, mixed $allowedIps = null): array
    {
        $user = (new User())->findById($userId);
        if (!$user) {
            throw new \InvalidArgumentException('Usuário não encontrado.');
        }
        $scopes = self::normalizeScopes($scopes ?? self::LEGACY_SCOPES);
        if ($expiresAt !== null && (strtotime($expiresAt) === false || strtotime($expiresAt) <= time())) {
            throw new \InvalidArgumentException('A validade precisa ser uma data futura.');
        }
        $ips = self::normalizeIps($allowedIps);
        $token = self::newToken();
        $this->pdo()->prepare('INSERT INTO erp_integration_tokens (client, name, user_id, token_hash, token_hint, scopes, created_by, created_at, expires_at, allowed_ips)
            VALUES (:client, :name, :user, :hash, :hint, :scopes, :by, NOW(), :expires, :ips)')->execute([
            'client' => self::CLIENT, 'name' => mb_substr(trim($name) ?: 'Connect Talk', 0, 80), 'user' => $userId,
            'hash' => hash('sha256', $token), 'hint' => substr($token, -4), 'scopes' => implode(',', $scopes), 'by' => $createdBy,
            'expires' => $expiresAt !== null ? date('Y-m-d H:i:s', (int)strtotime($expiresAt)) : null, 'ips' => $ips,
        ]);
        $id = (int)$this->pdo()->lastInsertId();
        Audit::record('create', 'erp_integration_tokens', $id, [], ['client' => self::CLIENT, 'user_id' => $userId, 'token_hint' => substr($token, -4),
            'scopes' => implode(',', $scopes), 'expires_at' => $expiresAt, 'allowed_ips' => $ips]);
        return ['id' => $id, 'token' => $token];
    }

    /** Usuário do token, ou null. Atalho do resolve() para quem só precisa do usuário. */
    public function authenticate(?string $token, ?string $ip = null): ?User
    {
        return $this->resolve($token, $ip)['user'] ?? null;
    }

    /**
     * Confere o token: existe, do Talk, não revogado, não vencido, IP na lista do token, usuário ativo e com
     * support.manage. Marca o último uso e o último IP (no máximo a cada TOUCH_EVERY segundos, ou IP novo).
     *
     * @return array{id:int, name:string, user:User, scopes:list<string>, expires_at:?string}|null
     */
    public function resolve(?string $token, ?string $ip = null): ?array
    {
        $hash = self::hashOf($token);
        if ($hash === null) {
            return null;
        }
        $stmt = $this->pdo()->prepare('SELECT id, name, user_id, scopes, expires_at, allowed_ips, revoked_at, last_used_at, last_ip FROM erp_integration_tokens
            WHERE token_hash = :hash AND client = :client AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(['hash' => $hash, 'client' => self::CLIENT]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !self::usable($row, $ip)) {
            return null;
        }
        $user = (new User())->findById((int)$row['user_id']);
        if (!$user || (string)$user->status !== 'confirmed' || !\Source\Support\Access::can(self::PERMISSION, $user)) {
            return null;
        }
        $ipChanged = $ip !== null && $ip !== ($row['last_ip'] ?? null);
        if ($ipChanged || $row['last_used_at'] === null || strtotime((string)$row['last_used_at']) < time() - self::TOUCH_EVERY) {
            $this->pdo()->prepare('UPDATE erp_integration_tokens SET last_used_at = NOW(), last_ip = COALESCE(:ip, last_ip) WHERE id = :id')
                ->execute(['ip' => $ip !== null ? mb_substr($ip, 0, 45) : null, 'id' => (int)$row['id']]);
        }
        return ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'user' => $user,
            'scopes' => array_values(array_intersect(array_keys(self::SCOPES), explode(',', (string)$row['scopes']))),
            'expires_at' => $row['expires_at'] !== null ? (string)$row['expires_at'] : null];
    }

    /** Token desta requisição (null fora do middleware). */
    public static function setCurrent(?array $token): void
    {
        self::$current = $token;
    }

    public static function current(): ?array
    {
        return self::$current;
    }

    /** O token desta requisição tem o escopo? Sem token na requisição = não. */
    public static function allows(string $scope): bool
    {
        return self::$current !== null && in_array($scope, self::$current['scopes'] ?? [], true);
    }

    /** Conta um pedido do token; true = passou de RATE na janela (429). */
    public static function overRate(int $tokenId, ?\Source\Support\IpRateLimit $limit = null, ?int $now = null): bool
    {
        [$max, $window] = self::RATE;
        return ($limit ?? new \Source\Support\IpRateLimit())->hit('talk-token-rate', 'token:' . $tokenId, $max, $window, $now);
    }

    /** O que o próprio token vê de si (GET /talk/me): nome, escopos, validade e condomínios do usuário de integração. */
    public function describe(array $token): array
    {
        $condominiums = array_map(static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']],
            (new \Source\Services\CondominiumScope($this->pdo()))->allowed($token['user']));
        return ['id' => $token['id'], 'name' => $token['name'], 'scopes' => $token['scopes'], 'expires_at' => $token['expires_at'],
            'rate_limit' => ['requests' => self::RATE[0], 'seconds' => self::RATE[1]], 'condominiums' => $condominiums];
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

    /**
     * Tokens do Talk, mais novos primeiro, sem o hash.
     *
     * @return list<array{id:int, name:string, user_id:int, user:string, hint:string, scopes:list<string>, created_at:string,
     *     expires_at:?string, allowed_ips:?string, last_used_at:?string, last_ip:?string, revoked_at:?string, revoke_reason:?string}>
     */
    public function list(): array
    {
        $stmt = $this->pdo()->prepare("SELECT t.id, t.name, t.user_id, TRIM(CONCAT(u.first_name, ' ', u.last_name)) AS user, t.token_hint AS hint,
                t.scopes, t.created_at, t.expires_at, t.allowed_ips, t.last_used_at, t.last_ip, t.revoked_at, t.revoke_reason
            FROM erp_integration_tokens t JOIN users u ON u.id = t.user_id WHERE t.client = ? ORDER BY t.id DESC");
        $stmt->execute([self::CLIENT]);
        $null = static fn (mixed $v): ?string => $v !== null ? (string)$v : null;
        return array_map(static fn (array $r): array => ['id' => (int)$r['id'], 'name' => (string)$r['name'], 'user_id' => (int)$r['user_id'],
            'user' => (string)$r['user'], 'hint' => (string)$r['hint'],
            'scopes' => array_values(array_intersect(array_keys(self::SCOPES), explode(',', (string)$r['scopes']))),
            'created_at' => (string)$r['created_at'], 'expires_at' => $null($r['expires_at']), 'allowed_ips' => $null($r['allowed_ips']),
            'last_used_at' => $null($r['last_used_at']), 'last_ip' => $null($r['last_ip']),
            'revoked_at' => $null($r['revoked_at']), 'revoke_reason' => $null($r['revoke_reason'])], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Usuários que podem ser o "usuário de integração": ativos e com support.manage.
     *
     * @return list<array{id:int, name:string, email:string}>
     */
    public function eligibleUsers(): array
    {
        $rows = $this->pdo()->query("SELECT id, TRIM(CONCAT(first_name, ' ', last_name)) AS name, email FROM users WHERE status = 'confirmed' ORDER BY first_name, last_name, id")
            ->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $user = (new User())->findById((int)$row['id']);
            if ($user && \Source\Support\Access::can(self::PERMISSION, $user)) {
                $out[] = ['id' => (int)$row['id'], 'name' => (string)$row['name'], 'email' => (string)$row['email']];
            }
        }
        return $out;
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
