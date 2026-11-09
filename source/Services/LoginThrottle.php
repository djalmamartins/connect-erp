<?php

declare(strict_types=1);

namespace Source\Services;

use PDO;
use Source\Core\Connect;
use Source\Support\AppLogger;

/**
 * Limite de tentativas de login e 2FA por conta e por IP, guardado no banco
 * (auth_throttle_events). Complementa request_limit(), que é por sessão e pode ser
 * contornado abrindo uma sessão nova (issue #7, risco 5; ADR 0023).
 *
 * Conta e IP nunca são gravados em texto: o banco guarda só um HMAC do identificador.
 * Se a tabela faltar ou o banco falhar, o limitador registra log e não bloqueia
 * (o limite por sessão continua valendo).
 */
final class LoginThrottle
{
    public const LOGIN = 'login';
    public const TWO_FACTOR = '2fa';

    /** [escopo => [tipo => [máximo de falhas, janela em segundos]]] */
    public const LIMITS = [
        self::LOGIN => ['account' => [6, 900], 'ip' => [30, 900]],
        self::TWO_FACTOR => ['account' => [6, 900], 'ip' => [30, 900]],
    ];

    /** Linhas mais antigas que isto são apagadas a cada falha registrada. */
    private const RETENTION = 86400;

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Identificadores (hash) da conta e do IP para um escopo. */
    public static function subjects(?string $account, ?string $ip): array
    {
        $subjects = [];
        $account = $account !== null ? mb_strtolower(trim($account)) : '';
        if ($account !== '') {
            $subjects['account'] = self::hash('account:' . $account);
        }
        $ip = $ip !== null ? (filter_var($ip, FILTER_VALIDATE_IP) ?: '') : '';
        if ($ip !== '') {
            $subjects['ip'] = self::hash('ip:' . $ip);
        }
        return $subjects;
    }

    public static function hash(string $value): string
    {
        $key = (string)(getenv('CONNECT_2FA_KEY') ?: '');
        return hash_hmac('sha256', $value, 'connect-throttle|' . $key);
    }

    /** true quando a conta ou o IP passou do limite de falhas na janela. */
    public function blocked(string $scope, array $subjects, ?int $now = null): bool
    {
        $now ??= time();
        try {
            $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM auth_throttle_events
                WHERE scope = :scope AND subject_hash = :subject AND created_at > :since');
            foreach ($subjects as $type => $subject) {
                [$max, $window] = self::LIMITS[$scope][$type] ?? [null, null];
                if ($max === null) {
                    continue;
                }
                $stmt->execute(['scope' => $scope, 'subject' => $subject, 'since' => $now - $window]);
                if ((int)$stmt->fetchColumn() >= $max) {
                    AppLogger::log('warning', 'Tentativas bloqueadas pelo limite por conta/IP', [
                        'event_type' => 'authentication_throttled', 'code' => 'AUTH_THROTTLED',
                        'scope' => $scope, 'subject_type' => $type, 'status' => 'resolved',
                    ], 'authentication');
                    return true;
                }
            }
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }
        return false;
    }

    /** Registra uma falha para cada identificador e apaga eventos antigos. */
    public function fail(string $scope, array $subjects, ?int $now = null): void
    {
        $now ??= time();
        try {
            $pdo = $this->pdo();
            $insert = $pdo->prepare('INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES (:scope, :subject, :now)');
            foreach ($subjects as $subject) {
                $insert->execute(['scope' => $scope, 'subject' => $subject, 'now' => $now]);
            }
            $pdo->prepare('DELETE FROM auth_throttle_events WHERE created_at < :before')->execute(['before' => $now - self::RETENTION]);
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }
    }

    /** Depois de um sucesso, zera as falhas da conta (o contador do IP continua). */
    public function clear(string $scope, array $subjects): void
    {
        if (!isset($subjects['account'])) {
            return;
        }
        try {
            $this->pdo()->prepare('DELETE FROM auth_throttle_events WHERE scope = :scope AND subject_hash = :subject')
                ->execute(['scope' => $scope, 'subject' => $subjects['account']]);
        } catch (\Throwable $error) {
            $this->unavailable($error);
        }
    }

    private function pdo(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }

    private function unavailable(\Throwable $error): void
    {
        AppLogger::log('error', 'Limite de tentativas por conta/IP indisponível', [
            'event_type' => 'authentication_throttle_unavailable', 'code' => 'AUTH_THROTTLE_UNAVAILABLE',
            'error' => $error->getMessage(), 'status' => 'open',
        ], 'authentication');
    }
}
