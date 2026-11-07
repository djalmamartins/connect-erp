<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Criptografia simétrica AES-256-GCM para segredos guardados no banco
 * (ex.: segredo TOTP). A chave vem de CONNECT_2FA_KEY (32 bytes em base64)
 * e nunca é versionada.
 */
final class SecretBox
{
    private const CIPHER = 'aes-256-gcm';
    private const VERSION = "\x01";

    private string $key;

    public function __construct(?string $base64Key = null)
    {
        $base64Key ??= (string)(getenv('CONNECT_2FA_KEY') ?: '');
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new \RuntimeException('CONNECT_2FA_KEY ausente ou inválida: defina 32 bytes em base64 no ambiente.');
        }
        $this->key = $key;
    }

    public static function isConfigured(): bool
    {
        $key = base64_decode((string)(getenv('CONNECT_2FA_KEY') ?: ''), true);
        return $key !== false && strlen($key) === 32;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(32));
    }

    public function encrypt(string $plaintext, string $context = ''): string
    {
        $nonce = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $nonce, $tag, $context, 16);
        if ($cipher === false) {
            throw new \RuntimeException('Falha ao proteger o segredo.');
        }
        return self::VERSION . $nonce . $tag . $cipher;
    }

    public function decrypt(string $payload, string $context = ''): string
    {
        if (strlen($payload) < 29 || $payload[0] !== self::VERSION) {
            throw new \RuntimeException('Segredo protegido em formato inválido.');
        }
        $plain = openssl_decrypt(substr($payload, 29), self::CIPHER, $this->key, OPENSSL_RAW_DATA, substr($payload, 1, 12), substr($payload, 13, 16), $context);
        if ($plain === false) {
            throw new \RuntimeException('Não foi possível validar o segredo protegido.');
        }
        return $plain;
    }
}
