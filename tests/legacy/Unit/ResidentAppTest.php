<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\App\Resident\AppLogin;
use Source\Controllers\App\Resident\AppScreens;
use Source\Services\Environments;
use Source\Services\Erp\ResidentPortalPermissions;
use Source\Services\Resident\ResidentFeatureDisabled;
use Source\Services\Resident\ResidentLinks;
use Source\Services\Resident\ResidentPortal;

/**
 * ADR 0302: regras puras do aplicativo do morador (validade do vínculo, escolha da unidade, funções visíveis, menu,
 * período do extrato, máscara do documento e o host app). As leituras e gravações no banco ficam em
 * Integration\ResidentAppIntegrationTest.
 */
final class ResidentAppTest extends TestCase
{
    public function testLinkIsValidOnlyInsideItsDates(): void
    {
        self::assertTrue(ResidentLinks::activeOn(null, null, '2026-11-14'));
        self::assertTrue(ResidentLinks::activeOn('', '', '2026-11-14'));
        self::assertTrue(ResidentLinks::activeOn('2026-11-14', '2026-11-14', '2026-11-14'), 'Os dois limites contam.');
        self::assertFalse(ResidentLinks::activeOn('2026-11-15', null, '2026-11-14'), 'Ainda não começou.');
        self::assertFalse(ResidentLinks::activeOn(null, '2026-11-13', '2026-11-14'), 'Já terminou.');
    }

    public function testPickUsesTheRequestedLinkOrTheFirst(): void
    {
        $links = [['resident_id' => 4], ['resident_id' => 9]];
        self::assertSame(9, ResidentLinks::pick($links, '9')['resident_id']);
        self::assertSame(4, ResidentLinks::pick($links, 77)['resident_id'], 'Vínculo de outra conta não é aceito.');
        self::assertSame(4, ResidentLinks::pick($links, 'abc')['resident_id']);
        self::assertSame(4, ResidentLinks::pick($links, null)['resident_id']);
        self::assertNull(ResidentLinks::pick([], 4));
    }

    public function testBillsAreOnlyForWhoPays(): void
    {
        $all = array_fill_keys(array_keys(ResidentPortalPermissions::FEATURES), true);
        foreach (ResidentLinks::FINANCIAL_RELATIONSHIPS as $relationship) {
            self::assertContains('boletos', ResidentLinks::visibleFeatures($all, $relationship));
        }
        $resident = ResidentLinks::visibleFeatures($all, 'resident');
        self::assertNotContains('boletos', $resident);
        self::assertContains('reservations', $resident);
        self::assertSame([], ResidentLinks::visibleFeatures([], 'owner'), 'Matriz desligada: nada aparece.');
        self::assertSame(['deliveries'], ResidentLinks::visibleFeatures(['deliveries' => true, 'visitors' => false], 'owner'));
    }

    public function testDisabledFeatureIsRefused(): void
    {
        ResidentPortal::assertFeature(['features' => ['deliveries']], 'deliveries');
        try {
            ResidentPortal::assertFeature(['features' => ['deliveries']], 'visitors');
            self::fail('Função desligada deveria ser recusada.');
        } catch (ResidentFeatureDisabled $exception) {
            self::assertSame('visitors', $exception->feature);
        }
    }

    public function testMenuFollowsTheFeatures(): void
    {
        $menu = AppScreens::menu(['boletos', 'deliveries'], 'charges');
        self::assertSame(['home', 'resolve', 'charges', 'unit', 'deliveries', 'profile'], array_column($menu, 'key'));
        self::assertSame([false, false, true, false, false, false], array_column($menu, 'active'));
        self::assertSame([true, true, false, true, false, true], array_column($menu, 'tab'), 'Barra inferior: Início, Resolva fácil, Unidade e Perfil.');
        self::assertSame([false, true, false, false, false, false], array_column($menu, 'tab_active'), 'Boletos acende a aba Resolva fácil.');
        self::assertSame(['home', 'resolve', 'unit', 'profile'], array_column(AppScreens::menu([], 'home'), 'key'), 'As quatro abas sempre aparecem.');
        self::assertSame('unit', array_column(array_filter(AppScreens::menu(['deliveries'], 'deliveries'), static fn (array $i): bool => $i['tab_active']), 'key')[0], 'Correspondências fica na aba Unidade.');
        self::assertSame('profile', array_column(array_filter(AppScreens::menu([], 'security'), static fn (array $i): bool => $i['tab_active']), 'key')[0], 'Segurança fica no Perfil.');
    }

    public function testStatementPeriodAndDates(): void
    {
        self::assertSame(['2025-11-01', '2027-02-28'], ResidentPortal::defaultPeriod('2026-11-14'));
        self::assertSame('2026-02-28', ResidentPortal::date('2026-02-28'));
        self::assertNull(ResidentPortal::date('2026-02-30'));
        self::assertNull(ResidentPortal::date('14/11/2026'));
        self::assertNull(ResidentPortal::date(20261114));
    }

    public function testVisitorDocumentIsMasked(): void
    {
        self::assertSame('******789', ResidentPortal::maskDocument('123456789'));
        self::assertSame('***', ResidentPortal::maskDocument('123'));
        self::assertNull(ResidentPortal::maskDocument('  '));
        self::assertNull(ResidentPortal::maskDocument(null));
    }

    public function testAppHostIsItsOwnEnvironment(): void
    {
        self::assertSame('app', Environments::hostRole('app.connect.lab'));
        self::assertSame('app', Environments::hostRole('app.connect.lab:8443'));
        self::assertSame(['host' => 'app.connect.lab', 'path' => '/'], Environments::target('residents', 'erp.connect.lab'));
        self::assertSame(['host' => 'erp.connect.lab', 'path' => '/dashboard'], Environments::target('erp', 'app.connect.lab'));
        self::assertSame(['host' => 'localhost', 'path' => '/app'], Environments::target('residents', 'localhost'));
    }

    public function testStaffWithoutUnitGetsAFriendlyNoticeOnTheLogin(): void
    {
        $notice = AppLogin::notResidentNotice((object)['first_name' => 'Djalma']);
        self::assertStringStartsWith('Olá, Djalma!', $notice);
        self::assertStringContainsString('não está cadastrada como morador', $notice);
        self::assertStringStartsWith('Olá! ', AppLogin::notResidentNotice((object)[]));
    }
}
