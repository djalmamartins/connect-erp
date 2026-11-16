<?php
declare(strict_types=1);

namespace Source\Services\Talk\Support;

/** Cifra a configuração dos canais (tokens) com libsodium. Chave: TALK_KEY (base64, 32 bytes). */
final class Crypto
{
    private string $key;
    private bool $ready;

    public function __construct(string $key)
    {
        $this->key = $key;
        $this->ready = true;
        if (strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \InvalidArgumentException('TALK_KEY precisa ter 32 bytes (base64 de 32 bytes)');
        }
    }

    public static function fromBase64(string $b64): self
    {
        return new self((string) base64_decode($b64, true));
    }

    /**
     * Sem TALK_KEY no .env do ERP (ADR 0323): o Talk abre (caixa, chamados, equipe), e só o que lê ou grava as
     * credenciais dos canais falha, com a mensagem de configuração.
     */
    public static function unconfigured(): self
    {
        $crypto = new self(str_repeat("\0", SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
        $crypto->ready = false;
        return $crypto;
    }

    public function configured(): bool
    {
        return $this->ready;
    }

    private function assertReady(): void
    {
        if (!$this->ready) {
            throw new \RuntimeException('Falta TALK_KEY no .env do ERP (base64 de 32 bytes) para guardar as credenciais dos canais.');
        }
    }

    public function encrypt(array $data): string
    {
        $this->assertReady();
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($data, JSON_THROW_ON_ERROR), $nonce, $this->key);
        return base64_encode($nonce . $box);
    }

    public function decrypt(string $payload): array
    {
        $this->assertReady();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new \RuntimeException('Configuração do canal inválida');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->key);
        if ($plain === false) {
            throw new \RuntimeException('Não foi possível decifrar a configuração do canal');
        }
        return json_decode($plain, true, 512, JSON_THROW_ON_ERROR);
    }
}
