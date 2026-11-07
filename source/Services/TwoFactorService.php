<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;
use Source\Support\SecretBox;
use Source\Support\Totp;

/**
 * Segundo fator (TOTP + códigos de recuperação) sobre a tabela users existente.
 * Segredos ficam cifrados (AES-256-GCM); códigos de recuperação ficam com hash.
 */
final class TwoFactorService
{
    public const RECOVERY_CODES = 10;

    private PDO $pdo;
    private Totp $totp;
    private ?SecretBox $box;

    public function __construct(?PDO $pdo = null, ?Totp $totp = null, ?SecretBox $box = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->totp = $totp ?? new Totp();
        $this->box = $box;
    }

    /** 2FA obrigatório a partir da data CONNECT_2FA_ENFORCE_FROM (AAAA-MM-DD). */
    public static function isEnforced(?int $now = null): bool
    {
        $from = trim((string)(getenv('CONNECT_2FA_ENFORCE_FROM') ?: ''));
        if ($from === '') {
            return false;
        }
        $start = strtotime($from . ' 00:00:00');
        return $start !== false && ($now ?? time()) >= $start;
    }

    public function isEnabled(int $userId): bool
    {
        $stmt = $this->pdo->prepare('SELECT confirmed_at FROM user_two_factor WHERE user_id = :user');
        $stmt->execute(['user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && !empty($row['confirmed_at']);
    }

    /** Gera (ou reaproveita) um segredo ainda não confirmado e o devolve em base32. */
    public function beginSetup(int $userId): string
    {
        if ($this->isEnabled($userId)) {
            throw new \DomainException('A autenticação em dois fatores já está ativa para este usuário.');
        }
        $pending = $this->pendingSecret($userId);
        if ($pending !== null) {
            return $pending;
        }
        $secret = Totp::generateSecret();
        $stmt = $this->pdo->prepare('INSERT INTO user_two_factor (user_id, secret_encrypted, confirmed_at, last_used_step)
            VALUES (:user, :secret, NULL, NULL)
            ON DUPLICATE KEY UPDATE secret_encrypted = VALUES(secret_encrypted), confirmed_at = NULL, last_used_step = NULL');
        $stmt->execute(['user' => $userId, 'secret' => $this->box()->encrypt($secret, $this->context($userId))]);
        return $secret;
    }

    public function pendingSecret(int $userId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT secret_encrypted FROM user_two_factor WHERE user_id = :user AND confirmed_at IS NULL');
        $stmt->execute(['user' => $userId]);
        $payload = $stmt->fetchColumn();
        return $payload === false ? null : $this->box()->decrypt((string)$payload, $this->context($userId));
    }

    public function provisioningUri(int $userId, string $secret, string $account, string $issuer = 'Connect ERP'): string
    {
        return $this->totp->uri($secret, $account, $issuer);
    }

    /**
     * Confirma o cadastro com um código válido e devolve os códigos de
     * recuperação em texto (exibidos uma única vez), ou null se o código falhar.
     *
     * @return list<string>|null
     */
    public function confirm(int $userId, string $code): ?array
    {
        $secret = $this->pendingSecret($userId);
        if ($secret === null) {
            return null;
        }
        $step = $this->totp->verify($secret, $code);
        if ($step === null) {
            $this->log('notice', 'Código inválido ao confirmar 2FA', $userId, 'TWO_FACTOR_CONFIRM_FAILED');
            return null;
        }
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('UPDATE user_two_factor SET confirmed_at = NOW(), last_used_step = :step WHERE user_id = :user AND confirmed_at IS NULL');
            $stmt->execute(['step' => $step, 'user' => $userId]);
            if ($stmt->rowCount() !== 1) {
                throw new \RuntimeException('Cadastro de 2FA alterado durante a confirmação.');
            }
            $codes = $this->storeRecoveryCodes($userId);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        $this->log('info', '2FA ativado', $userId, 'TWO_FACTOR_ENABLED');
        return $codes;
    }

    /** Valida um código TOTP com proteção contra reutilização. */
    public function verify(int $userId, string $code): bool
    {
        $stmt = $this->pdo->prepare('SELECT secret_encrypted, last_used_step FROM user_two_factor WHERE user_id = :user AND confirmed_at IS NOT NULL');
        $stmt->execute(['user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return false;
        }
        $last = $row['last_used_step'] === null ? null : (int)$row['last_used_step'];
        $step = $this->totp->verify($this->box()->decrypt((string)$row['secret_encrypted'], $this->context($userId)), $code, $last);
        if ($step === null) {
            $this->log('notice', 'Código 2FA inválido', $userId, 'TWO_FACTOR_FAILED');
            return false;
        }
        // Atualização condicional: duas requisições simultâneas com o mesmo código não passam.
        $update = $this->pdo->prepare('UPDATE user_two_factor SET last_used_step = :step
            WHERE user_id = :user AND (last_used_step IS NULL OR last_used_step < :step_check)');
        $update->execute(['step' => $step, 'step_check' => $step, 'user' => $userId]);
        return $update->rowCount() === 1;
    }

    /** Consome um código de recuperação de uso único. */
    public function verifyRecoveryCode(int $userId, string $code): bool
    {
        $normalized = self::normalizeRecoveryCode($code);
        if ($normalized === '' || !$this->isEnabled($userId)) {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT id, code_hash FROM user_recovery_codes WHERE user_id = :user AND used_at IS NULL');
        $stmt->execute(['user' => $userId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (password_verify($normalized, (string)$row['code_hash'])) {
                $use = $this->pdo->prepare('UPDATE user_recovery_codes SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
                $use->execute(['id' => $row['id']]);
                if ($use->rowCount() === 1) {
                    $this->log('warning', 'Código de recuperação 2FA utilizado', $userId, 'TWO_FACTOR_RECOVERY_USED');
                    return true;
                }
                return false;
            }
        }
        $this->log('notice', 'Código de recuperação 2FA inválido', $userId, 'TWO_FACTOR_RECOVERY_FAILED');
        return false;
    }

    public function remainingRecoveryCodes(int $userId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :user AND used_at IS NULL');
        $stmt->execute(['user' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(int $userId): array
    {
        if (!$this->isEnabled($userId)) {
            throw new \DomainException('Ative a autenticação em dois fatores antes de gerar códigos.');
        }
        $this->pdo->beginTransaction();
        try {
            $codes = $this->storeRecoveryCodes($userId);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        $this->log('info', 'Códigos de recuperação 2FA regenerados', $userId, 'TWO_FACTOR_RECOVERY_REGENERATED');
        return $codes;
    }

    /** Remove o 2FA (pelo próprio usuário ou por quem tem security.2fa.manage). */
    public function disable(int $userId, int $actorId): void
    {
        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :user')->execute(['user' => $userId]);
            $this->pdo->prepare('DELETE FROM user_two_factor WHERE user_id = :user')->execute(['user' => $userId]);
            $this->pdo->commit();
        } catch (\Throwable $error) {
            $this->pdo->rollBack();
            throw $error;
        }
        $this->log('warning', '2FA desativado', $userId, 'TWO_FACTOR_DISABLED', ['actor_id' => $actorId]);
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtolower(preg_replace('/[^a-z0-9]/i', '', $code) ?? '');
    }

    /** @return list<string> */
    private function storeRecoveryCodes(int $userId): array
    {
        $this->pdo->prepare('DELETE FROM user_recovery_codes WHERE user_id = :user')->execute(['user' => $userId]);
        $insert = $this->pdo->prepare('INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:user, :hash)');
        $codes = [];
        for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
            $raw = strtolower(substr(Totp::generateSecret(10), 0, 10));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
            $insert->execute(['user' => $userId, 'hash' => password_hash($raw, PASSWORD_DEFAULT)]);
        }
        return $codes;
    }

    private function box(): SecretBox
    {
        return $this->box ??= new SecretBox();
    }

    private function context(int $userId): string
    {
        return 'user_two_factor:' . $userId;
    }

    private function log(string $level, string $message, int $userId, string $code, array $extra = []): void
    {
        AppLogger::log($level, $message, ['event_type' => 'two_factor', 'code' => $code, 'user_id' => $userId, 'status' => 'resolved'] + $extra, 'authentication');
    }
}
