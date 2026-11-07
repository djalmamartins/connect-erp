<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\TwoFactorService;
use Source\Support\SecretBox;

final class SecretBoxTest extends TestCase
{
    public function testEncryptDecryptRoundTripWithRandomNonce(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $a = $box->encrypt('segredo', 'ctx');
        self::assertNotSame($a, $box->encrypt('segredo', 'ctx'));
        self::assertStringNotContainsString('segredo', $a);
        self::assertSame('segredo', $box->decrypt($a, 'ctx'));
    }

    public function testRejectsTamperedPayloadWrongContextAndWrongKey(): void
    {
        $box = new SecretBox(SecretBox::generateKey());
        $payload = $box->encrypt('segredo', 'user:1');
        $tampered = $payload;
        $tampered[strlen($tampered) - 1] = chr(ord($tampered[strlen($tampered) - 1]) ^ 1);
        foreach ([fn() => $box->decrypt($tampered, 'user:1'), fn() => $box->decrypt($payload, 'user:2'), fn() => (new SecretBox(SecretBox::generateKey()))->decrypt($payload, 'user:1')] as $attempt) {
            try {
                $attempt();
                self::fail('Payload adulterado foi aceito.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRequiresA32ByteKey(): void
    {
        $this->expectException(\RuntimeException::class);
        new SecretBox(base64_encode('curta'));
    }

    public function testRecoveryCodeNormalization(): void
    {
        self::assertSame('abcde12345', TwoFactorService::normalizeRecoveryCode(' ABCDE-12345 '));
    }

    public function testEnforcementDate(): void
    {
        putenv('CONNECT_2FA_ENFORCE_FROM=2026-11-14');
        try {
            self::assertFalse(TwoFactorService::isEnforced(strtotime('2026-11-13 23:59:59')));
            self::assertTrue(TwoFactorService::isEnforced(strtotime('2026-11-14 00:00:00')));
        } finally {
            putenv('CONNECT_2FA_ENFORCE_FROM');
        }
        self::assertFalse(TwoFactorService::isEnforced());
    }
}
