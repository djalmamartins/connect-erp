<?php

declare(strict_types=1);

namespace Source\Services\Privacy;

use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use Source\Support\ClientIp;

/**
 * Prova dos aceites e consentimentos (LGPD art. 8º, § 2º), ADR 0600. Tabela privacy_consents.
 *
 * Só acrescenta linhas. Retirar um consentimento grava uma linha nova com granted = 0; a última linha de cada finalidade vale.
 * Guarda o hash (sha256) do IP e do navegador, nunca o IP puro.
 */
final class ConsentLog
{
    public const PURPOSES = ['cookies', 'terms', 'privacy', 'marketing'];
    public const CHANNELS = ['site', 'proposta', 'convite', 'erp', 'app', 'celular', 'studio'];

    public function __construct(private ?PDO $pdo = null)
    {
    }

    /**
     * @param array{user_id?: int|null, proposal_id?: int|null, email?: string|null, detail?: string|null} $who
     */
    public function record(string $purpose, bool $granted, string $version, string $channel, array $who = []): int
    {
        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new InvalidArgumentException('Finalidade desconhecida.');
        }
        if (!in_array($channel, self::CHANNELS, true)) {
            throw new InvalidArgumentException('Canal desconhecido.');
        }
        $version = mb_substr(trim($version), 0, 20);
        if ($version === '') {
            throw new InvalidArgumentException('Versão obrigatória.');
        }
        $email = isset($who['email']) ? mb_substr(mb_strtolower(trim((string)$who['email'])), 0, 180) : null;
        $detail = isset($who['detail']) ? mb_substr(trim((string)$who['detail']), 0, 255) : null;
        $stmt = $this->db()->prepare('INSERT INTO privacy_consents
            (purpose, granted, version, detail, channel, user_id, proposal_id, email, request_hash)
            VALUES (:purpose, :granted, :version, :detail, :channel, :user_id, :proposal_id, :email, :hash)');
        $stmt->execute([
            'purpose' => $purpose,
            'granted' => $granted ? 1 : 0,
            'version' => $version,
            'detail' => $detail !== '' ? $detail : null,
            'channel' => $channel,
            'user_id' => isset($who['user_id']) && (int)$who['user_id'] > 0 ? (int)$who['user_id'] : null,
            'proposal_id' => isset($who['proposal_id']) && (int)$who['proposal_id'] > 0 ? (int)$who['proposal_id'] : null,
            'email' => $email !== '' ? $email : null,
            'hash' => self::requestHash(),
        ]);
        return (int)$this->db()->lastInsertId();
    }

    /** Última decisão da pessoa para a finalidade (null = nunca respondeu). */
    public function latestForUser(int $userId, string $purpose): ?array
    {
        $stmt = $this->db()->prepare('SELECT purpose, granted, version, channel, created_at FROM privacy_consents
            WHERE user_id = :user AND purpose = :purpose ORDER BY id DESC LIMIT 1');
        $stmt->execute(['user' => $userId, 'purpose' => $purpose]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function requestHash(?array $server = null): string
    {
        $server ??= $_SERVER;
        return hash('sha256', (ClientIp::resolve($server) ?? '') . '|' . mb_substr((string)($server['HTTP_USER_AGENT'] ?? ''), 0, 255));
    }

    private function db(): PDO
    {
        return $this->pdo ??= Connect::getInstance();
    }
}
