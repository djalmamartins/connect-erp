<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\TwoFactorAdminReset;

/** Issue #7, risco 4: regras do reset de 2FA por administrador (ADR 0025). */
final class TwoFactorAdminResetTest extends TestCase
{
    public function testAdminWithPermissionResetsLowerOrEqualLevelUser(): void
    {
        self::assertNull(TwoFactorAdminReset::denial(1, 9, true, 2, 5, true));
        self::assertNull(TwoFactorAdminReset::denial(1, 9, true, 2, 9, true));
    }

    public function testDenials(): void
    {
        self::assertSame('forbidden', TwoFactorAdminReset::denial(1, 10, false, 2, 1, true));
        self::assertSame('self_reset', TwoFactorAdminReset::denial(1, 10, true, 1, 10, true));
        self::assertSame('not_found', TwoFactorAdminReset::denial(1, 10, true, 2, null, false));
        self::assertSame('not_found', TwoFactorAdminReset::denial(1, 10, true, 0, null, false));
        self::assertSame('higher_level', TwoFactorAdminReset::denial(1, 5, true, 2, 10, true));
        self::assertSame('not_enabled', TwoFactorAdminReset::denial(1, 10, true, 2, 5, false));
    }

    public function testPermissionCheckComesFirstSoNothingLeaksWithoutIt(): void
    {
        // Sem a permissão, a resposta é a mesma exista ou não o usuário, tenha ou não 2FA.
        self::assertSame('forbidden', TwoFactorAdminReset::denial(1, 1, false, 99, null, false));
        self::assertSame('forbidden', TwoFactorAdminReset::denial(1, 1, false, 1, 1, true));
    }

    public function testStatusCodes(): void
    {
        self::assertSame(403, TwoFactorAdminReset::status('forbidden'));
        self::assertSame(403, TwoFactorAdminReset::status('higher_level'));
        self::assertSame(404, TwoFactorAdminReset::status('not_found'));
        self::assertSame(409, TwoFactorAdminReset::status('not_enabled'));
        self::assertSame(422, TwoFactorAdminReset::status('self_reset'));
    }

    public function testRouteIsAuthenticatedDelete(): void
    {
        $index = (string)file_get_contents(__DIR__ . '/../../../index.php');
        self::assertStringContainsString('$route->delete("/users/{id}/2fa", "UserTwoFactor:reset", null, \\Source\\Support\\Middleware\\ApiAuthenticated::class);', $index);
    }
}
