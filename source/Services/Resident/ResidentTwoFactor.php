<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;
use Source\Models\User;
use Source\Services\LoginThrottle;
use Source\Services\TwoFactorNotice;
use Source\Services\TwoFactorService;
use Source\Support\AppLogger;
use Source\Support\ClientIp;
use Source\Support\SecretBox;

/**
 * Verificação em duas etapas opcional para o morador (ADR 0304, pedido do Djalma em 14/11/2026).
 *
 * - Conta só de morador (vínculo de morador, nível < 5, nenhum papel além de "user" e nenhuma permissão liberada
 *   por exceção) fica fora da obrigatoriedade do CONNECT_2FA_ENFORCE_FROM: entra só com e-mail e senha. Se o
 *   morador ligar o 2FA no perfil, o código é pedido como para todo mundo.
 * - Conta da equipe que também mora no condomínio continua com o 2FA obrigatório: a exceção só vale para quem
 *   não tem acesso de equipe. Em dúvida (erro ao consultar), a regra é a obrigatória.
 * - Ligar, desligar e renovar os códigos seguem as regras do ERP (Erp\Connect\Security): ações com o 2FA ligado
 *   pedem o código atual; limite de tentativas no LoginThrottle (vale para a web e para o token do app nativo);
 *   aviso por e-mail e registro no log de autenticação (TwoFactorService).
 */
final class ResidentTwoFactor
{
    private PDO $pdo;
    private TwoFactorService $twoFactor;
    private LoginThrottle $throttle;
    /** @var callable(object, string): bool */
    private $notify;

    public function __construct(?PDO $pdo = null, ?TwoFactorService $twoFactor = null, ?LoginThrottle $throttle = null, ?callable $notify = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->twoFactor = $twoFactor ?? new TwoFactorService($this->pdo);
        $this->throttle = $throttle ?? new LoginThrottle($this->pdo);
        $this->notify = $notify ?? static fn (object $user, string $event): bool => (new TwoFactorNotice())->send($user, $event);
    }

    /**
     * Regra pura: conta só de morador?
     *
     * @param list<string> $roleSlugs papéis da conta
     */
    public static function residentOnly(int $level, array $roleSlugs, int $allowOverrides, bool $hasResidentLink): bool
    {
        if (!$hasResidentLink || $level >= 5 || $allowOverrides > 0) {
            return false;
        }
        foreach ($roleSlugs as $slug) {
            if ($slug !== 'user') {
                return false;
            }
        }
        return true;
    }

    /** Conta só de morador no banco (fail-closed: erro na consulta = não é). */
    public static function isResidentOnly(int $userId, ?PDO $pdo = null): bool
    {
        if ($userId <= 0) {
            return false;
        }
        try {
            $pdo ??= Connect::getInstance();
            $user = $pdo->prepare('SELECT level FROM users WHERE id = :u');
            $user->execute(['u' => $userId]);
            $level = $user->fetchColumn();
            if ($level === false) {
                return false;
            }
            $roles = $pdo->prepare('SELECT r.slug FROM access_user_roles ur JOIN access_roles r ON r.id = ur.role_id WHERE ur.user_id = :u');
            $roles->execute(['u' => $userId]);
            $overrides = $pdo->prepare("SELECT COUNT(*) FROM access_user_overrides WHERE user_id = :u AND effect = 'allow'");
            $overrides->execute(['u' => $userId]);
            $link = $pdo->prepare("SELECT 1 FROM erp_residents WHERE user_id = :u AND deleted_at IS NULL AND status = 'active' LIMIT 1");
            $link->execute(['u' => $userId]);
            return self::residentOnly((int)$level, array_map('strval', $roles->fetchAll(PDO::FETCH_COLUMN)), (int)$overrides->fetchColumn(), (bool)$link->fetchColumn());
        } catch (\Throwable $error) {
            AppLogger::exception($error, 'authentication', ['event_type' => 'two_factor', 'code' => 'RESIDENT_2FA_POLICY_CHECK_FAILED', 'user_id' => $userId]);
            return false;
        }
    }

    /** 2FA obrigatório para esta conta? (obrigatoriedade geral, menos para conta só de morador) */
    public static function enforcedFor(int $userId, ?PDO $pdo = null): bool
    {
        return TwoFactorService::isEnforced() && !self::isResidentOnly($userId, $pdo);
    }

    /**
     * Situação do 2FA da conta. Desligado e com a chave de cifra configurada: inclui o segredo e a URI
     * otpauth:// para o aplicativo autenticador (o mesmo segredo até confirmar).
     *
     * @return array{enabled:bool, configured:bool, required:bool, recovery_remaining:int, setup?:array{secret:string, uri:string}}
     */
    public function status(User $user): array
    {
        $userId = (int)$user->id;
        $enabled = $this->twoFactor->isEnabled($userId);
        $configured = SecretBox::isConfigured();
        $status = [
            'enabled' => $enabled,
            'configured' => $configured,
            'required' => self::enforcedFor($userId, $this->pdo),
            'recovery_remaining' => $enabled ? $this->twoFactor->remainingRecoveryCodes($userId) : 0,
        ];
        if (!$enabled && $configured) {
            $secret = $this->twoFactor->pendingSecret($userId) ?? $this->twoFactor->beginSetup($userId);
            $status['setup'] = ['secret' => $secret, 'uri' => $this->twoFactor->provisioningUri($userId, $secret, (string)$user->email)];
        }
        return $status;
    }

    /**
     * Liga (confirm), desliga (disable) ou renova os códigos (recovery).
     *
     * @return array{ok:true, recovery_codes?:list<string>}|array{ok:false, status:int, code:string, message:string}
     */
    public function act(User $user, string $action, mixed $code): array
    {
        $userId = (int)$user->id;
        $subjects = LoginThrottle::subjects('user:' . $userId, ClientIp::resolve());
        if ($this->throttle->blocked(LoginThrottle::TWO_FACTOR, $subjects)) {
            return self::fail(429, 'too_many_attempts', 'Muitas tentativas. Aguarde alguns minutos.');
        }
        $code = is_string($code) ? (preg_replace('/\s+/', '', $code) ?? '') : '';
        $enabled = $this->twoFactor->isEnabled($userId);

        if ($action === 'confirm') {
            if ($enabled) {
                return self::fail(409, 'already_enabled', 'A verificação em duas etapas já está ligada.');
            }
            $codes = $code === '' ? null : $this->twoFactor->confirm($userId, $code);
            if ($codes === null) {
                return $this->invalid($subjects);
            }
            $this->throttle->clear(LoginThrottle::TWO_FACTOR, $subjects);
            ($this->notify)($user, TwoFactorNotice::ENABLED);
            return ['ok' => true, 'recovery_codes' => array_values(array_map('strval', $codes))];
        }
        if (!in_array($action, ['disable', 'recovery'], true)) {
            return self::fail(422, 'invalid_action', 'Ação não reconhecida.');
        }
        if (!$enabled) {
            return self::fail(409, 'not_enabled', 'A verificação em duas etapas está desligada.');
        }
        if ($code === '' || !$this->twoFactor->verify($userId, $code)) {
            return $this->invalid($subjects);
        }
        $this->throttle->clear(LoginThrottle::TWO_FACTOR, $subjects);
        if ($action === 'disable') {
            if (self::enforcedFor($userId, $this->pdo)) {
                return self::fail(409, 'two_factor_required', 'A verificação em duas etapas é obrigatória para esta conta e não pode ser desligada.');
            }
            $this->twoFactor->disable($userId, $userId);
            ($this->notify)($user, TwoFactorNotice::DISABLED);
            return ['ok' => true];
        }
        return ['ok' => true, 'recovery_codes' => array_values(array_map('strval', $this->twoFactor->regenerateRecoveryCodes($userId)))];
    }

    /** @param list<string> $subjects */
    private function invalid(array $subjects): array
    {
        $this->throttle->fail(LoginThrottle::TWO_FACTOR, $subjects);
        return self::fail(422, 'invalid_code', 'Código inválido. Confira o código no seu aplicativo autenticador.');
    }

    /** @return array{ok:false, status:int, code:string, message:string} */
    private static function fail(int $status, string $code, string $message): array
    {
        return ['ok' => false, 'status' => $status, 'code' => $code, 'message' => $message];
    }
}
