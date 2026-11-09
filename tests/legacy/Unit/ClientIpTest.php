<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\ClientIp;

/** ADR 0023 (adendo): IP do cliente atrás de proxy confiável, sem confiar em X-Forwarded-For forjado. */
final class ClientIpTest extends TestCase
{
    public function testWithoutTrustedProxiesForwardedHeaderIsIgnored(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        self::assertSame('203.0.113.9', ClientIp::resolve($server, ''));
    }

    public function testUntrustedRemoteCannotSpoofForwardedHeader(): void
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'];
        self::assertSame('203.0.113.9', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function testTrustedProxyYieldsRightmostUntrustedHop(): void
    {
        // O cliente forjou 1.2.3.4 à esquerda; o proxy confiável acrescentou o IP real.
        $server = ['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4, 198.51.100.7, 10.0.0.2'];
        self::assertSame('198.51.100.7', ClientIp::resolve($server, '10.0.0.0/8'));
    }

    public function testIpv6RangesAndExactAddresses(): void
    {
        self::assertSame('2001:db8::9', ClientIp::resolve(['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::9'], 'fd00::/8'));
        self::assertSame('198.51.100.7', ClientIp::resolve(['REMOTE_ADDR' => '172.18.0.3', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '172.18.0.3'));
        self::assertSame('172.18.0.4', ClientIp::resolve(['REMOTE_ADDR' => '172.18.0.4', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '172.18.0.3'));
    }

    public function testInvalidValuesFallBackToRemoteOrNull(): void
    {
        self::assertSame('10.0.0.5', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => 'lixo'], '10.0.0.0/8'));
        self::assertSame('10.0.0.5', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5'], '10.0.0.0/8'));
        self::assertSame('10.0.0.5', ClientIp::resolve(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7'], '10.0.0.0/99, x'));
        self::assertNull(ClientIp::resolve(['REMOTE_ADDR' => 'nope'], '10.0.0.0/8'));
    }

    public function testLoginThrottleUsesClientIp(): void
    {
        $auth = (string)file_get_contents(__DIR__ . '/../../../source/Models/Auth.php');
        self::assertSame(2, substr_count($auth, 'ClientIp::resolve()'));
    }
}
