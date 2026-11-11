<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

use RuntimeException;
use Source\Support\SecretBox;

/**
 * Cofre das credenciais bancárias (ADR 0156). Cifra com AES-256-GCM (SecretBox) usando a chave
 * CONNECT_BANK_VAULT_KEY (32 bytes em base64, só no ambiente, nunca no Git). É uma chave separada
 * da do 2FA: trocar uma não invalida a outra. O contexto amarra o segredo à conta bancária, então
 * um segredo copiado para outra linha não decifra.
 */
final class BankVault
{
    public const ENV_KEY = 'CONNECT_BANK_VAULT_KEY';

    private SecretBox $box;

    public function __construct(?string $base64Key = null)
    {
        $base64Key ??= (string)(getenv(self::ENV_KEY) ?: '');
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException(self::ENV_KEY . ' ausente ou inválida: defina 32 bytes em base64 no ambiente (php tools/bank-credentials.php gerar-chave).');
        }
        $this->box = new SecretBox($base64Key);
    }

    public static function isConfigured(): bool
    {
        $key = base64_decode((string)(getenv(self::ENV_KEY) ?: ''), true);
        return $key !== false && strlen($key) === 32;
    }

    /** @param array<string, string> $secrets */
    public function seal(array $secrets, string $context): string
    {
        return $this->box->encrypt(json_encode($secrets, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), $context);
    }

    /** @return array<string, string> */
    public function open(string $payload, string $context): array
    {
        $data = json_decode($this->box->decrypt($payload, $context), true);
        if (!is_array($data)) {
            throw new RuntimeException('Credencial bancária protegida em formato inválido.');
        }
        return array_map('strval', $data);
    }

    public function sealText(string $plain, string $context): string
    {
        return $this->box->encrypt($plain, $context);
    }

    public function openText(string $payload, string $context): string
    {
        return $this->box->decrypt($payload, $context);
    }
}
