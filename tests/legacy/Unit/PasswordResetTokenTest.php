<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\PasswordResetToken;

/** Geração, validação e expiração do token de recuperação de senha. */
final class PasswordResetTokenTest extends TestCase
{
    public function testGeneratesUnique256BitHexTokens(): void
    {
        $tokens = array_map(static fn(): string => PasswordResetToken::generate(), range(1, 50));
        foreach ($tokens as $token) {
            self::assertMatchesRegularExpression('~^[a-f0-9]{64}$~', $token);
            self::assertTrue(PasswordResetToken::isWellFormed($token));
        }
        self::assertCount(50, array_unique($tokens));
    }

    public function testOnlyTheSha256HashIsPersistableAndItDiffersFromToken(): void
    {
        $token = PasswordResetToken::generate();
        $hash = PasswordResetToken::hash($token);
        self::assertSame(hash('sha256', $token), $hash);
        self::assertNotSame($token, $hash);
        self::assertSame(64, strlen($hash));
    }

    public function testRejectsMalformedTokensIncludingLegacyCodeWithEmail(): void
    {
        $valid = PasswordResetToken::generate();
        foreach ([
            '',
            substr($valid, 0, 63),
            $valid . 'a',
            strtoupper($valid),
            md5('x') . ':ana@connect.test',
            str_repeat('g', 64),
            "../{$valid}",
        ] as $token) {
            self::assertFalse(PasswordResetToken::isWellFormed($token), "Aceitou: {$token}");
        }
    }

    public function testExpiresAfterShortTtlIncludingBoundary(): void
    {
        $now = 1_800_000_000;
        $expiresAt = PasswordResetToken::expiresAt($now);

        self::assertSame(1800, PasswordResetToken::TTL_SECONDS);
        self::assertFalse(PasswordResetToken::isExpired($expiresAt, $now));
        self::assertFalse(PasswordResetToken::isExpired($expiresAt, $now + PasswordResetToken::TTL_SECONDS - 1));
        self::assertTrue(PasswordResetToken::isExpired($expiresAt, $now + PasswordResetToken::TTL_SECONDS));
        self::assertTrue(PasswordResetToken::isExpired('data-invalida', $now), 'Data ilegível conta como expirada.');
    }

    public function testWebRoutesExposeRecoveryWithTokenOnlyLink(): void
    {
        $route = new class {
            public array $calls = [];
            public function __call(string $name, array $args): void { $this->calls[] = [$name, ...$args]; }
        };
        require moves_container_path('web', 'default') . '/routes.php';

        self::assertContains(['get', '/forget', 'Web:forget'], $route->calls);
        self::assertContains(['post', '/forget', 'Web:forget'], $route->calls);
        self::assertContains(['get', '/forget/{code}', 'Web:reset'], $route->calls);
        self::assertContains(['post', '/reset', 'Web:reset'], $route->calls);
    }
}
