<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use DateTimeImmutable;
use PDO;
use Source\Core\Connect;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\SessionRevocation;
use Source\Services\TwoFactorService;
use Source\Support\AppLogger;
use Source\Support\Audit;
use Source\Support\ClientIp;

/**
 * Sessões do app nativo do morador (ADR 0303). O app (React Native, apps/morador) não usa cookie: entra com
 * e-mail ou CPF e senha e recebe um token Bearer, guardado no cofre do aparelho. As rotas /api/v1/resident
 * (ADR 0302) aceitam esse token pelo ResidentApiAuthenticated, que chama authenticate() e Auth::actingAs().
 *
 * - login(): mesma conta, senha e limite de tentativas do login web (Auth::attempt, LoginThrottle). Só entra
 *   quem tem vínculo de morador ativo (ResidentLinks). Com 2FA ativo devolve um desafio de uso único e o token só
 *   sai em verify(). 2FA obrigatório e ainda não configurado = 409: configurar primeiro pela web
 *   (conta só de morador não tem 2FA obrigatório, ADR 0304).
 * - authenticate(): token válido = usuário. Vale TTL_DAYS dias e cai com IDLE_DAYS dias sem uso, com o logout
 *   global (ADR 0036) ou se o usuário deixou de ser morador.
 * - revoke() / revokeAll(): sair deste aparelho / de todos.
 * Segurança: só o sha256 do token e do desafio ficam no banco; nada disso vai para log ou auditoria.
 */
final class ResidentAppSessions
{
    public const TTL_DAYS = 90;
    public const IDLE_DAYS = 30;
    public const MAX_DEVICES = 10;
    public const CHALLENGE_TTL = 300;
    public const CHALLENGE_ATTEMPTS = 5;
    /** Atualiza last_used_at no máximo uma vez por este intervalo (segundos). */
    private const TOUCH_EVERY = 300;

    private PDO $pdo;
    private TwoFactorService $twoFactor;
    private LoginThrottle $throttle;
    /** @var callable(User):bool */
    private $isResident;
    /** @var callable(int):bool 2FA obrigatório para a conta (ADR 0304: conta só de morador fica de fora) */
    private $enforced;

    public function __construct(?PDO $pdo = null, ?TwoFactorService $twoFactor = null, ?LoginThrottle $throttle = null, ?callable $isResident = null, ?callable $enforced = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->twoFactor = $twoFactor ?? new TwoFactorService($this->pdo);
        $this->throttle = $throttle ?? new LoginThrottle($this->pdo);
        $this->isResident = $isResident ?? static fn (User $user): bool => (new ResidentLinks())->forUser($user) !== [];
        $this->enforced = $enforced ?? fn (int $userId): bool => ResidentTwoFactor::enforcedFor($userId, $this->pdo);
    }

    /** Token novo (regra pura): 64 caracteres hexadecimais de random_bytes. */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** sha256 do token (regra pura). Formato inválido = null, sem consulta ao banco. */
    public static function hashOf(mixed $token): ?string
    {
        return is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token) === 1 ? hash('sha256', $token) : null;
    }

    /** Token do cabeçalho "Authorization: Bearer ..." (regra pura). Ausente ou malformado = null. */
    public static function bearerFrom(?string $header): ?string
    {
        if ($header === null || preg_match('/^\s*Bearer\s+([0-9a-f]{64})\s*$/i', $header, $m) !== 1) {
            return null;
        }
        return strtolower($m[1]);
    }

    /** Cabeçalho Authorization da requisição atual (o Apache às vezes só repassa REDIRECT_HTTP_AUTHORIZATION). */
    public static function requestBearer(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        return self::bearerFrom(is_string($header) ? $header : null);
    }

    /** Nome do aparelho limpo (regra pura): só texto visível, até 80 caracteres. */
    public static function deviceName(mixed $name): ?string
    {
        if (!is_string($name)) {
            return null;
        }
        $clean = trim((string)preg_replace('/[\x00-\x1F\x7F]+/u', ' ', strip_tags($name)));
        return $clean === '' ? null : mb_substr($clean, 0, 80);
    }

    /**
     * Situação de um token guardado (regra pura): 'active', 'revoked', 'expired' ou 'idle'.
     *
     * @param array{revoked_at:?string, expires_at:string, last_used_at:string} $row
     */
    public static function state(array $row, DateTimeImmutable $now): string
    {
        if (!empty($row['revoked_at'])) {
            return 'revoked';
        }
        if (new DateTimeImmutable((string)$row['expires_at']) <= $now) {
            return 'expired';
        }
        if (new DateTimeImmutable((string)$row['last_used_at']) <= $now->modify('-' . self::IDLE_DAYS . ' days')) {
            return 'idle';
        }
        return 'active';
    }

    /**
     * Primeiro passo do login pelo app.
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function login(string $login, string $password, mixed $device): array
    {
        $subjects = LoginThrottle::subjects(Auth::throttleAccount($login), ClientIp::resolve());
        if ($this->throttle->blocked(LoginThrottle::LOGIN, $subjects)) {
            return self::error(429, 'too_many_attempts', Auth::THROTTLED_MESSAGE);
        }
        $user = (new Auth())->attempt($login, $password, 1);
        if (!$user) {
            $this->throttle->fail(LoginThrottle::LOGIN, $subjects);
            AppLogger::log('notice', 'Tentativa de login no app do morador não concluída', ['event_type' => 'authentication_failed', 'code' => 'RESIDENT_APP_AUTH_FAILED', 'identity_hash' => hash('sha256', mb_strtolower(trim($login))), 'status' => 'resolved'], 'authentication');
            return self::error(401, 'invalid_credentials', 'E-mail, CPF ou senha não conferem.');
        }
        $this->throttle->clear(LoginThrottle::LOGIN, $subjects);
        if (!($this->isResident)($user)) {
            return self::error(403, 'not_resident', 'Esta conta não tem unidade ativa no app do morador. Fale com a administração.');
        }
        $userId = (int)$user->id;
        try {
            $enabled = $this->twoFactor->isEnabled($userId);
        } catch (\PDOException $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'two_factor', 'code' => 'RESIDENT_APP_2FA_UNAVAILABLE']);
            return self::error(503, 'unavailable', 'O acesso está temporariamente indisponível. Tente de novo em alguns minutos.');
        }
        if ($enabled) {
            $challenge = self::newToken();
            $this->pdo->prepare('INSERT INTO erp_resident_app_challenges (user_id, challenge_hash, device_name, expires_at) VALUES (:user, :hash, :device, :expires)')
                ->execute(['user' => $userId, 'hash' => hash('sha256', $challenge), 'device' => self::deviceName($device), 'expires' => date('Y-m-d H:i:s', time() + self::CHALLENGE_TTL)]);
            return ['status' => 202, 'body' => ['data' => ['two_factor_required' => true, 'challenge' => $challenge]]];
        }
        if (($this->enforced)($userId)) {
            return self::error(409, 'two_factor_setup_required', 'Ative a verificação em duas etapas pelo site antes de usar o app.');
        }
        return ['status' => 201, 'body' => ['data' => $this->issue($user, self::deviceName($device))]];
    }

    /**
     * Segundo passo (código do autenticador ou de recuperação).
     *
     * @return array{status:int, body:array<string,mixed>}
     */
    public function verify(mixed $challenge, mixed $code): array
    {
        $hash = self::hashOf($challenge);
        $row = null;
        if ($hash !== null) {
            $stmt = $this->pdo->prepare('SELECT id, user_id, device_name, attempts, expires_at, used_at FROM erp_resident_app_challenges WHERE challenge_hash = :hash LIMIT 1');
            $stmt->execute(['hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($row === null || $row['used_at'] !== null || strtotime((string)$row['expires_at']) <= time() || (int)$row['attempts'] >= self::CHALLENGE_ATTEMPTS) {
            return self::error(401, 'challenge_expired', 'A verificação expirou. Entre de novo com e-mail e senha.');
        }
        $userId = (int)$row['user_id'];
        $subjects = LoginThrottle::subjects('user:' . $userId, ClientIp::resolve());
        if ($this->throttle->blocked(LoginThrottle::TWO_FACTOR, $subjects)) {
            return self::error(429, 'too_many_attempts', Auth::THROTTLED_MESSAGE);
        }
        $code = is_string($code) ? trim($code) : '';
        $valid = $code !== '' && (preg_match('/^\d{6}$/', (string)preg_replace('/\s/', '', $code)) === 1
            ? $this->twoFactor->verify($userId, $code)
            : $this->twoFactor->verifyRecoveryCode($userId, $code));
        if (!$valid) {
            $this->throttle->fail(LoginThrottle::TWO_FACTOR, $subjects);
            $this->pdo->prepare('UPDATE erp_resident_app_challenges SET attempts = attempts + 1 WHERE id = :id')->execute(['id' => $row['id']]);
            return self::error(422, 'invalid_code', 'Código inválido.', ['code' => 'Confira o código no seu aplicativo autenticador.']);
        }
        // Uso único: só um pedido consome o desafio.
        $use = $this->pdo->prepare('UPDATE erp_resident_app_challenges SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $use->execute(['id' => $row['id']]);
        $user = (new User())->findById($userId);
        if ($use->rowCount() !== 1 || !$user || !($this->isResident)($user)) {
            return self::error(401, 'challenge_expired', 'A verificação expirou. Entre de novo com e-mail e senha.');
        }
        $this->throttle->clear(LoginThrottle::TWO_FACTOR, $subjects);
        return ['status' => 201, 'body' => ['data' => $this->issue($user, $row['device_name'] !== null ? (string)$row['device_name'] : null)]];
    }

    /** Usuário do token, ou null (ausente, revogado, vencido, parado, logout global, sem vínculo de morador). */
    public function authenticate(?string $token, ?DateTimeImmutable $now = null): ?User
    {
        $hash = self::hashOf($token);
        if ($hash === null) {
            return null;
        }
        $now ??= new DateTimeImmutable();
        $stmt = $this->pdo->prepare('SELECT id, user_id, created_at_ms, last_used_at, expires_at, revoked_at FROM erp_resident_app_tokens WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || self::state($row, $now) !== 'active') {
            return null;
        }
        if ((new SessionRevocation($this->pdo))->isRevoked((int)$row['user_id'], (int)$row['created_at_ms'])) {
            $this->revokeRow((int)$row['id'], 'logout_all');
            return null;
        }
        $user = (new User())->findById((int)$row['user_id']);
        if (!$user || !($this->isResident)($user)) {
            return null;
        }
        if (strtotime((string)$row['last_used_at']) <= $now->getTimestamp() - self::TOUCH_EVERY) {
            $this->pdo->prepare('UPDATE erp_resident_app_tokens SET last_used_at = :now WHERE id = :id')
                ->execute(['now' => $now->format('Y-m-d H:i:s'), 'id' => $row['id']]);
        }
        return $user;
    }

    /** Sai deste aparelho. true se havia uma sessão ativa com o token. */
    public function revoke(?string $token): bool
    {
        $hash = self::hashOf($token);
        if ($hash === null) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id, user_id FROM erp_resident_app_tokens WHERE token_hash = :hash AND revoked_at IS NULL LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return false;
        }
        $this->revokeRow((int)$row['id'], 'logout');
        Audit::record('resident_app.logout', 'erp_resident_app_tokens', (int)$row['id']);
        return true;
    }

    /** Sai de todos os aparelhos do usuário. Devolve quantas sessões caíram. */
    public function revokeAll(int $userId, string $reason = 'logout_all'): int
    {
        $stmt = $this->pdo->prepare('UPDATE erp_resident_app_tokens SET revoked_at = NOW(), revoke_reason = :reason WHERE user_id = :user AND revoked_at IS NULL');
        $stmt->execute(['reason' => mb_substr($reason, 0, 40), 'user' => $userId]);
        return $stmt->rowCount();
    }

    /** @return array{token:string, expires_at:string, idle_days:int} */
    private function issue(User $user, ?string $device): array
    {
        $token = self::newToken();
        $now = time();
        $expires = date('Y-m-d H:i:s', $now + self::TTL_DAYS * 86400);
        $this->pdo->prepare('INSERT INTO erp_resident_app_tokens (user_id, token_hash, device_name, created_at, created_at_ms, last_used_at, expires_at) VALUES (:user, :hash, :device, :now, :ms, :now, :expires)')
            ->execute(['user' => (int)$user->id, 'hash' => hash('sha256', $token), 'device' => $device, 'now' => date('Y-m-d H:i:s', $now), 'ms' => SessionRevocation::nowMs() + 1, 'expires' => $expires]);
        $id = (int)$this->pdo->lastInsertId();
        // Teto de aparelhos: as sessões ativas mais antigas além do limite caem.
        $old = $this->pdo->prepare('SELECT id FROM erp_resident_app_tokens WHERE user_id = :user AND revoked_at IS NULL ORDER BY id DESC LIMIT 1000 OFFSET ' . self::MAX_DEVICES);
        $old->execute(['user' => (int)$user->id]);
        foreach ($old->fetchAll(PDO::FETCH_COLUMN) as $oldId) {
            $this->revokeRow((int)$oldId, 'device_limit');
        }
        Audit::record('resident_app.login', 'erp_resident_app_tokens', $id, null, ['user_id' => (int)$user->id, 'device_name' => $device]);
        return ['token' => $token, 'expires_at' => date('Y-m-d', strtotime($expires)), 'idle_days' => self::IDLE_DAYS];
    }

    private function revokeRow(int $id, string $reason): void
    {
        $this->pdo->prepare('UPDATE erp_resident_app_tokens SET revoked_at = NOW(), revoke_reason = :reason WHERE id = :id AND revoked_at IS NULL')
            ->execute(['reason' => $reason, 'id' => $id]);
    }

    /** @return array{status:int, body:array<string,mixed>} */
    private static function error(int $status, string $code, string $message, array $fields = []): array
    {
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== []) {
            $error['fields'] = $fields;
        }
        return ['status' => $status, 'body' => ['error' => $error]];
    }
}
