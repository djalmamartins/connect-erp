<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Source\Support\Access;

final class AccessFallbackTest extends TestCase
{
    public static function strictPermissions(): array
    {
        return [['erp.access'], ['erp.condominiums.view'], ['api.condominiums.read'], ['security.2fa.manage']];
    }

    #[DataProvider('strictPermissions')]
    public function testStrictPrefixesNeverFallBackToLegacyLevel(string $permission): void
    {
        self::assertTrue(Access::isStrict($permission));
        self::assertFalse(Access::fallback($permission, 10));
        self::assertFalse(Access::fallback($permission, 2));
    }

    public function testNonStrictPermissionsKeepStudioLegacyLevel(): void
    {
        self::assertFalse(Access::isStrict('studio.access'));
        self::assertFalse(Access::isStrict('erpx.access'));
        self::assertTrue(Access::fallback('articles.manage', 5));
        self::assertFalse(Access::fallback('articles.manage', 4));
    }

    public function testMissingUserIsDeniedWithoutTouchingDatabase(): void
    {
        self::assertFalse(Access::can('erp.access', null));
        self::assertFalse(Access::can('studio.access', null));
    }
}
