<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;
use Source\Support\PasswordResetToken;

/**
 * Emissão e consumo de tokens de recuperação de senha (user_password_resets).
 * O token em texto só existe no link enviado por e-mail; o banco guarda o hash.
 */
final class PasswordResetService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
    }

    /** Invalida pedidos anteriores do usuário e devolve um token novo em texto. */
    public function issue(int $userId, ?string $ip = null, ?int $now = null): string
    {
        $token = PasswordResetToken::generate();
        $this->pdo->prepare('UPDATE user_password_resets SET used_at = NOW() WHERE user_id = :user AND used_at IS NULL')
            ->execute(['user' => $userId]);
        $this->pdo->prepare('INSERT INTO user_password_resets (user_id, token_hash, expires_at, requested_ip) VALUES (:user, :hash, :expires, :ip)')
            ->execute([
                'user' => $userId,
                'hash' => PasswordResetToken::hash($token),
                'expires' => PasswordResetToken::expiresAt($now),
                'ip' => $ip !== null ? substr($ip, 0, 45) : null,
            ]);
        return $token;
    }

    /** Usuário dono de um token válido (não usado e não expirado), sem consumi-lo. */
    public function findValidUserId(string $token, ?int $now = null): ?int
    {
        $row = $this->lookup($token);
        if ($row === null || $row['used_at'] !== null || PasswordResetToken::isExpired((string)$row['expires_at'], $now)) {
            return null;
        }
        return (int)$row['user_id'];
    }

    /**
     * Consome o token de forma atômica e devolve o usuário, ou null se inválido,
     * expirado ou já usado. Use dentro da mesma transação da troca de senha.
     */
    public function consume(string $token, ?int $now = null): ?int
    {
        $row = $this->lookup($token);
        if ($row === null || $row['used_at'] !== null || PasswordResetToken::isExpired((string)$row['expires_at'], $now)) {
            AppLogger::log('notice', 'Token de recuperação de senha inválido', [
                'event_type' => 'password_reset', 'code' => 'PASSWORD_RESET_INVALID_TOKEN', 'status' => 'resolved',
            ], 'authentication');
            return null;
        }
        $update = $this->pdo->prepare('UPDATE user_password_resets SET used_at = NOW() WHERE id = :id AND used_at IS NULL');
        $update->execute(['id' => $row['id']]);
        return $update->rowCount() === 1 ? (int)$row['user_id'] : null;
    }

    /** @return array{id:int|string,user_id:int|string,expires_at:string,used_at:?string}|null */
    private function lookup(string $token): ?array
    {
        if (!PasswordResetToken::isWellFormed($token)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT id, user_id, expires_at, used_at FROM user_password_resets WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => PasswordResetToken::hash($token)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}
