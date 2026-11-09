<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use PDO;
use Source\Core\Connect;
use Source\Services\LoginThrottle;
use Source\Support\AppLogger;

/**
 * Limite de tentativas do convite do morador (ADR 0093), que falha fechado: se a tabela
 * auth_throttle_events não puder ser lida ou gravada, a resposta é UNAVAILABLE, e quem chama recusa o
 * pedido com UNAVAILABLE_MESSAGE. Vale para abrir e para aceitar. Os identificadores são só HMAC (o IP e o
 * sha256 do token passam por LoginThrottle::hash); o log nunca leva token, IP nem hash.
 */
final class InviteThrottle
{
    public const OK = 'ok';
    public const BLOCKED = 'blocked';
    public const UNAVAILABLE = 'unavailable';
    public const SCOPE = 'invite';
    public const UNAVAILABLE_MESSAGE = 'Não foi possível conferir o convite agora. Tente de novo mais tarde.';
    /** [tipo => [máximo de falhas, janela em segundos]] */
    public const LIMITS = ['ip' => [20, 900], 'token' => [5, 900]];

    private ?PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo;
    }

    /** Identificadores (regra pura): hash do IP (se válido) e hash do sha256 do token (se houver). */
    public static function subjects(?string $ip, ?string $token): array
    {
        $subjects = [];
        $ip = $ip !== null ? (filter_var($ip, FILTER_VALIDATE_IP) ?: '') : '';
        if ($ip !== '') {
            $subjects['ip'] = LoginThrottle::hash('invite-ip:' . $ip);
        }
        if ($token !== null && $token !== '') {
            $subjects['token'] = LoginThrottle::hash('invite-token:' . hash('sha256', $token));
        }
        return $subjects;
    }

    /** OK, BLOCKED (passou do limite) ou UNAVAILABLE (não deu para ler: recusar). */
    public function check(array $subjects, ?int $now = null): string
    {
        $now ??= time();
        try {
            $stmt = $this->pdo()->prepare('SELECT COUNT(*) FROM auth_throttle_events WHERE scope = :s AND subject_hash = :h AND created_at > :since');
            foreach ($subjects as $type => $hash) {
                [$max, $window] = self::LIMITS[$type] ?? [null, null];
                if ($max === null) {
                    continue;
                }
                $stmt->execute(['s' => self::SCOPE, 'h' => $hash, 'since' => $now - $window]);
                if ((int)$stmt->fetchColumn() >= $max) {
                    return self::BLOCKED;
                }
            }
            return self::OK;
        } catch (\Throwable $error) {
            self::unavailable('read', $error);
            return self::UNAVAILABLE;
        }
    }

    /** Registra uma falha; false = não gravou (quem chama responde UNAVAILABLE_MESSAGE). */
    public function fail(array $subjects, ?int $now = null): bool
    {
        try {
            $insert = $this->pdo()->prepare('INSERT INTO auth_throttle_events (scope, subject_hash, created_at) VALUES (:s, :h, :t)');
            foreach ($subjects as $hash) {
                $insert->execute(['s' => self::SCOPE, 'h' => $hash, 't' => $now ?? time()]);
            }
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

    /** Log sem token, IP nem hash: só a operação e a classe do erro. */
    private static function unavailable(string $operation, \Throwable $error): void
    {
        AppLogger::log('error', 'Limite do convite indisponível: pedido recusado', ['event_type' => 'invite_throttle_unavailable',
            'code' => 'INVITE_THROTTLE_UNAVAILABLE', 'operation' => $operation, 'error_class' => $error::class, 'status' => 'open'], 'authentication');
    }
}
