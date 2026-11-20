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

    public function testAllowedListAcceptsIpsAndCidrAndEmptyMeansAll(): void
    {
        self::assertTrue(ClientIp::allowed('198.51.100.9', ''));
        self::assertTrue(ClientIp::allowed(null, ' , '));
        self::assertTrue(ClientIp::allowed('203.0.113.77', '198.51.100.9, 203.0.113.0/24'));
        self::assertTrue(ClientIp::allowed('198.51.100.9', '198.51.100.9'));
        self::assertTrue(ClientIp::allowed('2001:db8::5', '2001:db8::/32'));
        self::assertFalse(ClientIp::allowed('198.51.100.10', '198.51.100.9, 203.0.113.0/24'));
        self::assertFalse(ClientIp::allowed(null, '203.0.113.0/24'));
        self::assertFalse(ClientIp::allowed('203.0.113.77', 'lixo, 10.0.0.0/99'));
    }

    public function testBankWebhookChecksTheIpListBeforeReadingTheBody(): void
    {
        $source = (string)file_get_contents(__DIR__ . '/../../../source/Controllers/Api/V1/BankWebhook.php');
        $check = strpos($source, "ClientIp::allowed(ClientIp::resolve(), (string)(getenv('BANK_WEBHOOK_ALLOWED_IPS')");
        self::assertNotFalse($check, 'webhook do banco sem a lista de IPs (ADR 0360)');
        self::assertLessThan(strpos($source, 'php://input'), $check);
        self::assertLessThan(strpos($source, '(new BankWebhooks())->receive('), $check);
    }

    public function testBankWebhookRefusesAnIpOutsideTheList(): void
    {
        $server = $_SERVER;
        $previous = getenv('BANK_WEBHOOK_ALLOWED_IPS');
        putenv('BANK_WEBHOOK_ALLOWED_IPS=203.0.113.0/24');
        $_SERVER['REMOTE_ADDR'] = '198.51.100.9';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        ob_start();
        try {
            (new \Source\Controllers\Api\V1\BankWebhook())->receive(['adapter' => 'inter', 'secret' => str_repeat('a', 48)]);
        } finally {
            $out = (string)ob_get_clean();
            $_SERVER = $server;
            putenv($previous === false ? 'BANK_WEBHOOK_ALLOWED_IPS' : 'BANK_WEBHOOK_ALLOWED_IPS=' . $previous);
        }
        self::assertStringContainsString('"not_found"', $out);
    }

    public function testLoginThrottleUsesClientIp(): void
    {
        $auth = (string)file_get_contents(__DIR__ . '/../../../source/Models/Auth.php');
        self::assertSame(2, substr_count($auth, 'ClientIp::resolve()'));
    }
}
