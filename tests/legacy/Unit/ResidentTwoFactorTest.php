<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Resident\ResidentTwoFactor;

/**
 * ADR 0304: só a conta de morador sem acesso da equipe fica fora do 2FA obrigatório. As leituras no banco, a
 * dispensa no login e as rotas /api/v1/resident/2fa ficam em Integration\ResidentTwoFactorIntegrationTest.
 */
final class ResidentTwoFactorTest extends TestCase
{
    public function testPlainResidentIsExempt(): void
    {
        self::assertTrue(ResidentTwoFactor::residentOnly(1, ['user'], 0, true));
        self::assertTrue(ResidentTwoFactor::residentOnly(1, [], 0, true), 'Sem papel também é só morador.');
    }

    public function testStaffOrAccountWithoutLinkIsNotExempt(): void
    {
        self::assertFalse(ResidentTwoFactor::residentOnly(1, ['user'], 0, false), 'Sem vínculo de morador.');
        self::assertFalse(ResidentTwoFactor::residentOnly(5, ['user'], 0, true), 'Nível de equipe no Studio.');
        self::assertFalse(ResidentTwoFactor::residentOnly(1, ['user', 'erp_partner'], 0, true), 'Papel de equipe ou parceiro.');
        self::assertFalse(ResidentTwoFactor::residentOnly(1, ['super_admin'], 0, true));
        self::assertFalse(ResidentTwoFactor::residentOnly(1, ['user'], 1, true), 'Permissão liberada por exceção.');
    }

    public function testInvalidUserIsNeverExempt(): void
    {
        self::assertFalse(ResidentTwoFactor::isResidentOnly(0));
    }
}
