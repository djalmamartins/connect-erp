<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Services\LoginThrottle;
use Source\Support\AppLogger;

/**
 * Limite da verificação pública de documentos (ADR 0098), por IP, que falha fechado como o do convite
 * (ADR 0093). Se a tabela auth_throttle_events não puder ser lida ou gravada, a resposta é UNAVAILABLE.
 * Só o código errado (inválido, inexistente ou cancelado) conta como falha. O identificador é só HMAC
 * (LoginThrottle::hash), e o log nunca leva IP, código nem hash.
 */
final class DocumentVerificationThrottle
{
    public const OK = 'ok';
    public const BLOCKED = 'blocked';
    public const UNAVAILABLE = 'unavailable';
    public const SCOPE = 'doc_verify';
    public const MAX_FAILURES = 20;
    public const WINDOW = 900;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Identificador do IP (regra pura), ou null se o IP não for válido (quem chama recusa). */
    public static function subject(?string $ip): ?string
    {
        $ip = $ip !== null ? (filter_var($ip, FILTER_VALIDATE_IP) ?: '') : '';
        return $ip !== '' ? LoginThrottle::hash('doc-verify-ip:' . $ip) : null;
    }

    public function check(string $subject, ?int $now = null): string
    {
        try {
            $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM auth_throttle_events WHERE scope = :s AND subject_hash = :h AND created_at > :since');
            $stmt->execute(['s' => self::SCOPE, 'h' => $subject, 'since' => ($now ?? time()) - self::WINDOW]);
            return (int)$stmt->fetchColumn() >= self::MAX_FAILURES ? self::BLOCKED : self::OK;
        } catch (\Throwable $error) {
            self::unavailable('read', $error);
            return self::UNAVAILABLE;
        }
    }

    /** Registra uma falha; false = não gravou (quem chama recusa). */
    public function fail(string $subject, ?int $now = null): bool
    {
        try {
            $this->pdo()->prepare('INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES (:s, :h, :t)')
                ->execute(['s' => self::SCOPE, 'h' => $subject, 't' => $now ?? time()]);
            return true;
        } catch (\Throwable $error) {
            self::unavailable('write', $error);
            return false;
        }
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }

    private static function unavailable(string $operation, \Throwable $error): void
    {
        try {
            AppLogger::log('error', 'Limite da verificação de documentos indisponível: pedido recusado', ['event_type' => 'doc_verify_throttle_unavailable',
                'code' => 'DOC_VERIFY_THROTTLE_UNAVAILABLE', 'operation' => $operation, 'error_class' => $error::class, 'status' => 'open'], 'authentication');
        } catch (\Throwable) {
            // o log não muda a decisão
        }
    }
}
