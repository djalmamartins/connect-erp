<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\Totp;

final class TotpTest extends TestCase
{
    /** Segredo ASCII "12345678901234567890" dos vetores do RFC 6238 (SHA-1). */
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public static function rfcVectors(): array
    {
        return [[59, '287082'], [1111111109, '081804'], [1111111111, '050471'], [1234567890, '005924'], [2000000000, '279037']];
    }

    /** @dataProvider rfcVectors */
    #[\PHPUnit\Framework\Attributes\DataProvider('rfcVectors')]
    public function testMatchesRfc6238Vectors(int $time, string $expected): void
    {
        $totp = new Totp();
        self::assertSame($expected, $totp->code(self::RFC_SECRET, $totp->step($time)));
    }

    public function testBase32RoundTrip(): void
    {
        self::assertSame(self::RFC_SECRET, Totp::base32Encode('12345678901234567890'));
        self::assertSame('12345678901234567890', Totp::base32Decode(strtolower(self::RFC_SECRET)));
    }

    public function testVerifyAcceptsAdjacentWindowAndRejectsOthers(): void
    {
        $totp = new Totp();
        $now = 1_700_000_000;
        $current = $totp->step($now);
        self::assertSame($current - 1, $totp->verify(self::RFC_SECRET, $totp->code(self::RFC_SECRET, $current - 1), null, 1, $now));
        self::assertSame($current + 1, $totp->verify(self::RFC_SECRET, $totp->code(self::RFC_SECRET, $current + 1), null, 1, $now));
        self::assertNull($totp->verify(self::RFC_SECRET, $totp->code(self::RFC_SECRET, $current - 3), null, 1, $now));
        self::assertNull($totp->verify(self::RFC_SECRET, '12345', null, 1, $now));
        self::assertNull($totp->verify(self::RFC_SECRET, 'abcdef', null, 1, $now));
    }

    public function testVerifyRejectsReplayOfUsedStep(): void
    {
        $totp = new Totp();
        $now = 1_700_000_000;
        $current = $totp->step($now);
        $code = $totp->code(self::RFC_SECRET, $current);
        self::assertSame($current, $totp->verify(self::RFC_SECRET, $code, null, 1, $now));
        self::assertNull($totp->verify(self::RFC_SECRET, $code, $current, 1, $now));
    }

    public function testGeneratedSecretsAreUniqueBase32(): void
    {
        $a = Totp::generateSecret();
        self::assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $a);
        self::assertNotSame($a, Totp::generateSecret());
    }

    public function testProvisioningUriEncodesIssuerAndAccount(): void
    {
        $uri = (new Totp())->uri(self::RFC_SECRET, 'ana@connect.test', 'Connect ERP');
        self::assertStringStartsWith('otpauth://totp/Connect%20ERP:ana%40connect.test?', $uri);
        self::assertStringContainsString('secret=' . self::RFC_SECRET, $uri);
    }
}
