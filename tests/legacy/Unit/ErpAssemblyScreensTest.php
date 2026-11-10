<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\AssemblyScreens as Screens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\Assemblies;

/**
 * Telas de assembleia (ADR 0145): tela própria só com dados, a assembleia escolhida em cada tela, as ações
 * por situação e o texto do resultado, com os rótulos cobrindo todos os valores do serviço.
 */
final class ErpAssemblyScreensTest extends TestCase
{
    public function testPagesTemplatesAndPermissions(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (array_keys(Screens::PAGES) as $page) {
            self::assertSame('erp.assemblies.view', $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, ['assemblyReady' => false]));
            self::assertSame('pages/assemblies/assembly', Panel::ownTemplate($page, ['assemblyReady' => true]));
        }
    }

    public function testLabelsCoverTheServiceValues(): void
    {
        self::assertSame(Assemblies::KINDS, array_keys(Screens::KINDS));
        self::assertSame(Assemblies::MODES, array_keys(Screens::MODES));
        self::assertSame(Assemblies::BASES, array_keys(Screens::BASES));
        self::assertSame(Assemblies::CHOICES, array_keys(Screens::CHOICES));
    }

    public function testSelectedAssembly(): void
    {
        $list = [['id' => 5, 'status' => 'draft'], ['id' => 4, 'status' => 'closed'], ['id' => 3, 'status' => 'open'], ['id' => 2, 'status' => 'open']];
        self::assertSame(3, Screens::selected(null, $list, ['open']));
        self::assertSame(4, Screens::selected('', $list, ['closed', 'open']));
        self::assertSame(9, Screens::selected('9', $list, ['open']));
        self::assertNull(Screens::selected('0', $list, []));
        self::assertNull(Screens::selected(null, [['id' => 1, 'status' => 'cancelled']], ['open']));
    }

    public function testActionsAndResultText(): void
    {
        self::assertSame(['convene', 'cancel'], Screens::actions('draft', true, true));
        self::assertSame(['cancel'], Screens::actions('draft', true, false));
        self::assertSame(['open', 'cancel'], Screens::actions('convened', true, false));
        self::assertSame(['close'], Screens::actions('open', true, false));
        self::assertSame([], Screens::actions('cancelled', true, true));
        self::assertSame([], Screens::actions('draft', false, true));
        self::assertSame('—', Screens::resultText(['vote_basis' => 'fraction', 'result' => null]));
        self::assertSame('Sim 0,600000 · Não 0,400000 · Abstenção 0,000000 de 1,000000 da fração ideal',
            Screens::resultText(['vote_basis' => 'fraction', 'result' => ['yes' => 600000, 'no' => 400000, 'abstain' => 0, 'total' => 1000000]]));
        self::assertSame(2, Screens::votingItem([['id' => 1, 'status' => 'decided'], ['id' => 2, 'status' => 'voting']]));
        self::assertNull(Screens::votingItem([['id' => 1, 'status' => 'pending']]));
        self::assertSame('—', Screens::stamp(null));
        self::assertSame('01/12/2026 19:00', Screens::stamp('2026-12-01 19:00:00'));
    }
}
