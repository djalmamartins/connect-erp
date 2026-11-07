<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * TOTP (RFC 6238) com HMAC-SHA1, 6 dígitos e passo de 30 segundos,
 * compatível com Google Authenticator, Microsoft Authenticator e similares.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly string $algorithm = 'sha1'
    ) {
    }

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), $this->period);
    }

    public function code(string $base32Secret, int $step): string
    {
        $key = self::base32Decode($base32Secret);
        $counter = pack('N2', ($step >> 32) & 0xFFFFFFFF, $step & 0xFFFFFFFF);
        $hash = hash_hmac($this->algorithm, $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $value = ((ord($hash[$offset]) & 0x7F) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);
        return str_pad((string)($value % (10 ** $this->digits)), $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Retorna o passo aceito ou null. Rejeita passos iguais ou anteriores a
     * $lastUsedStep para impedir a reutilização do mesmo código (replay).
     */
    public function verify(string $base32Secret, string $code, ?int $lastUsedStep = null, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== $this->digits) {
            return null;
        }
        $current = $this->step($timestamp);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $current + $offset;
            if ($step < 0 || ($lastUsedStep !== null && $step <= $lastUsedStep)) {
                continue;
            }
            if (hash_equals($this->code($base32Secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    public function uri(string $base32Secret, string $account, string $issuer): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);
        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => strtoupper($this->algorithm),
            'digits' => $this->digits,
            'period' => $this->period,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $output;
    }

    public static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[\s=-]/', '', $secret) ?? '');
        if ($secret === '' || strspn($secret, self::ALPHABET) !== strlen($secret)) {
            throw new \InvalidArgumentException('Segredo TOTP inválido.');
        }
        $bits = '';
        foreach (str_split($secret) as $char) {
            $bits .= str_pad(decbin(strpos(self::ALPHABET, $char)), 5, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }
        return $output;
    }
}
