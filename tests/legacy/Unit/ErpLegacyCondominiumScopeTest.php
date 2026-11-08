<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Erp;

/**
 * Issue #7, risco 7: o controller ERP legado só aceita condomínio dentro do escopo do
 * usuário e não cai mais no condomínio 0001 quando nada foi escolhido.
 */
final class ErpLegacyCondominiumScopeTest extends TestCase
{
    public function testRequestedIdInsideScopeIsAccepted(): void
    {
        self::assertSame(7, Erp::resolveCondominium('7', null, fn (int $id): bool => $id === 7));
    }

    public function testRequestedIdOutsideScopeIsDeniedWithoutFallingBackToSaved(): void
    {
        self::assertNull(Erp::resolveCondominium('9', 7, fn (int $id): bool => $id === 7));
    }

    public function testSavedIdIsRecheckedAgainstScope(): void
    {
        self::assertSame(7, Erp::resolveCondominium(null, 7, fn (int $id): bool => $id === 7));
        self::assertNull(Erp::resolveCondominium(null, 3, fn (int $id): bool => $id === 7));
    }

    public function testNothingChosenNeverDefaultsToFirstCondominium(): void
    {
        $asked = [];
        self::assertNull(Erp::resolveCondominium(null, null, function (int $id) use (&$asked): bool { $asked[] = $id; return true; }));
        self::assertNull(Erp::resolveCondominium('', '0', fn (): bool => true));
        self::assertSame([], $asked);
    }

    public function testInvalidIdsAndScopeErrorsAreDenied(): void
    {
        self::assertNull(Erp::resolveCondominium('1 OR 1=1', null, fn (): bool => true));
        self::assertNull(Erp::resolveCondominium('-2', null, fn (): bool => true));
        self::assertNull(Erp::resolveCondominium('5', null, fn (): bool => throw new \RuntimeException('db')));
    }
}
