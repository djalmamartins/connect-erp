<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Models\Auth;

/** Issue #7, risco 2: sem a tabela do 2FA, o login só segue sem 2FA antes da obrigatoriedade. */
final class TwoFactorSchemaMissingTest extends TestCase
{
    private string|false $previous;

    protected function setUp(): void
    {
        $this->previous = getenv('CONNECT_2FA_ENFORCE_FROM');
    }

    protected function tearDown(): void
    {
        putenv($this->previous === false ? 'CONNECT_2FA_ENFORCE_FROM' : 'CONNECT_2FA_ENFORCE_FROM=' . $this->previous);
    }

    public function testFailsClosedOnceTwoFactorIsEnforced(): void
    {
        putenv('CONNECT_2FA_ENFORCE_FROM=2026-01-01');
        self::assertSame('unavailable', Auth::modeWhenTwoFactorSchemaMissing(strtotime('2026-11-08 12:00:00')));
    }

    public function testKeepsLoginBeforeEnforcementDateOrWhenUnset(): void
    {
        putenv('CONNECT_2FA_ENFORCE_FROM=2027-01-01');
        self::assertNull(Auth::modeWhenTwoFactorSchemaMissing(strtotime('2026-11-08 12:00:00')));
        putenv('CONNECT_2FA_ENFORCE_FROM');
        self::assertNull(Auth::modeWhenTwoFactorSchemaMissing());
    }
}
