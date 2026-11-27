<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Source\Controllers\Erp\Connect\Panel;
use Source\Models\User;
use Source\Support\Access;

/** ADR 0681: tela sem permissão diz qual permissão falta em vez de mostrar a demonstração. */
final class ErpForbiddenScreenTest extends TestCase
{
    protected function tearDown(): void
    {
        Access::clear();
    }

    private static function seed(int $userId, array $decisions): User
    {
        $cache = (new ReflectionClass(Access::class))->getProperty('cache');
        $values = $cache->getValue();
        foreach ($decisions as $permission => $allowed) {
            $values[$userId . ':' . $permission] = $allowed;
        }
        $cache->setValue(null, $values);
        $user = new User();
        $user->id = $userId;
        return $user;
    }

    public function testRecordsOnlyStrictDenialsSinceTheMark(): void
    {
        $user = self::seed(4242, ['erp.units.view' => false, 'erp.access' => true, 'support.manage' => false, 'erp.closing.view' => false]);
        self::assertFalse(Access::can('erp.units.view', $user));   // antes da marca
        $mark = Access::deniedMark();
        self::assertTrue(Access::can('erp.access', $user));
        self::assertFalse(Access::can('support.manage', $user));   // não estrita: não conta
        self::assertFalse(Access::can('erp.closing.view', $user));
        self::assertFalse(Access::can('erp.closing.view', $user));  // do cache, sem repetir
        self::assertSame(['erp.closing.view'], Access::deniedSince($mark));
        self::assertSame([], Access::deniedSince(Access::deniedMark()));
    }

    public function testForbiddenStateNamesTheMissingPermissions(): void
    {
        $one = Panel::forbiddenState(['erp.units.view'], ['erp.units.view' => 'Visualizar unidades']);
        self::assertSame(403, $one['stateCode']);
        self::assertTrue($one['withNavigation']);
        self::assertStringContainsString('“Visualizar unidades”', $one['stateMessage']);
        self::assertStringContainsString('Permissões específicas', $one['stateMessage']);

        $two = Panel::forbiddenState(['erp.entries.view', 'erp.chart.view'], ['erp.entries.view' => 'Ver lançamentos']);
        self::assertStringContainsString('“Ver lançamentos” e “erp.chart.view”', $two['stateMessage']);
        self::assertSame(['erp.entries.view', 'erp.chart.view'], $two['stateMissing']);
    }
}
