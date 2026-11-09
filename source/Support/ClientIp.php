<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * IP do cliente para limites e logs (ADR 0023, adendo de 09/11/2026).
 *
 * Por padrão vale só REMOTE_ADDR: X-Forwarded-For é ignorado, porque qualquer um pode enviá-lo.
 * Atrás de proxy, CONNECT_TRUSTED_PROXIES lista os proxies confiáveis (IPs ou CIDR, separados
 * por vírgula). Se REMOTE_ADDR for um deles, o IP do cliente é o primeiro endereço não confiável
 * de X-Forwarded-For lido da direita para a esquerda.
 */
final class ClientIp
{
    /** @param array<string, mixed>|null $server */
    public static function resolve(?array $server = null, ?string $trusted = null): ?string
    {
        $server ??= $_SERVER;
        $remote = filter_var($server['REMOTE_ADDR'] ?? null, FILTER_VALIDATE_IP) ?: null;
        $proxies = self::parse($trusted ?? (string)(getenv('CONNECT_TRUSTED_PROXIES') ?: ''));
        if ($remote === null || $proxies === [] || !self::matches($remote, $proxies)) {
            return $remote;
        }
        $chain = array_reverse(array_map('trim', explode(',', (string)($server['HTTP_X_FORWARDED_FOR'] ?? ''))));
        foreach ($chain as $hop) {
            $ip = filter_var($hop, FILTER_VALIDATE_IP) ?: null;
            if ($ip === null) {
                return $remote;
            }
            if (!self::matches($ip, $proxies)) {
                return $ip;
            }
        }
        return $remote;
    }

    /** @return list<string> */
    private static function parse(string $list): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $list)), static fn (string $item): bool => $item !== ''));
    }

    /** @param list<string> $proxies */
    private static function matches(string $ip, array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            if (self::inRange($ip, $proxy)) {
                return true;
            }
        }
        return false;
    }

    private static function inRange(string $ip, string $range): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $range, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $subnetBin = @inet_pton((string)$subnet);
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }
        $max = strlen($ipBin) * 8;
        $bits = $bits === null ? $max : filter_var($bits, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => $max]]);
        if ($bits === false) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        if (substr($ipBin, 0, $bytes) !== substr($subnetBin, 0, $bytes)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = chr((0xFF << (8 - $rest)) & 0xFF);
        return (($ipBin[$bytes] & $mask) === ($subnetBin[$bytes] & $mask));
    }
}
