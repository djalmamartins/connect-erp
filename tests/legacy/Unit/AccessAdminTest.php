<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\PartnerAccess;
use Source\Services\Erp\ResidentInvitations;
use Source\Services\Erp\ResidentPortalPermissions;

/** Acesso de imobiliárias e moradores (ADR 0090): CNPJ, token, valor da matriz e fail-closed. */
final class AccessAdminTest extends TestCase
{
    public function testCnpj(): void
    {
        self::assertSame('11222333000181', PartnerAccess::validCnpj('11.222.333/0001-81'));
        self::assertNull(PartnerAccess::validCnpj('11.222.333/0001-82'));
        self::assertNull(PartnerAccess::validCnpj('00000000000000'));
        self::assertNull(PartnerAccess::validCnpj('123'));
    }

    public function testPartnerRoleIsFixed(): void
    {
        self::assertSame('erp_partner', PartnerAccess::PARTNER_ROLE);
        self::assertSame(1, PartnerAccess::MAX_USER_LEVEL, 'Só usuário comum vira parceiro.');
        $link = new \ReflectionMethod(PartnerAccess::class, 'linkUser');
        self::assertSame(['admin', 'agencyId', 'userId'], array_map(static fn ($p) => $p->getName(), $link->getParameters()), 'Não há parâmetro de papel.');
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0544_partner_resident_access.sql');
        self::assertMatchesRegularExpression("/r\.slug = 'erp_partner' AND p\.slug IN \('erp\.access','erp\.partner\.view'\)/", $sql, 'O papel só tem as duas permissões.');
    }

    public function testTokens(): void
    {
        $a = ResidentInvitations::newToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, ResidentInvitations::newToken());
        self::assertSame(hash('sha256', $a), ResidentInvitations::hashOf($a));
        foreach ([null, '', 'abc', str_repeat('g', 64), strtoupper($a), [$a]] as $bad) {
            self::assertNull(ResidentInvitations::hashOf($bad));
        }
        self::assertSame(7, ResidentInvitations::VALID_DAYS);
    }

    public function testPortalFlagAndFailClosed(): void
    {
        self::assertTrue(ResidentPortalPermissions::flag(true));
        self::assertFalse(ResidentPortalPermissions::flag('false'));
        self::assertFalse(ResidentPortalPermissions::flag(0));
        foreach (['sim', '', null, 'talvez'] as $bad) {
            try {
                ResidentPortalPermissions::flag($bad);
                self::fail('Aceitou valor ambíguo: ' . var_export($bad, true));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $pdo = new PDO('sqlite::memory:');
        self::assertFalse(ResidentPortalPermissions::enabled($pdo, 1, 'boletos'), 'Sem tabela ou sem linha: desligado.');
        self::assertFalse(ResidentPortalPermissions::enabled($pdo, 1, 'financeiro'), 'Função desconhecida: desligado.');
        self::assertFalse(ResidentPortalPermissions::enabled($pdo, 0, 'boletos'));
    }
}
