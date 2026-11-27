<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0657: o login do app do morador na web usa a tela de entrada do ERP (foto, dicas e cartão),
 * com o cartão "CONNECT MORADOR", e a recuperação de senha do morador volta ao login do app.
 */
final class ResidentLoginErpStyleTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testAppLoginRendersTheErpLoginScreen(): void
    {
        $code = (string)file_get_contents(self::ROOT . '/source/Controllers/App/Resident/AppLogin.php');
        self::assertStringContainsString("render('components/login/login'", $code);
        self::assertStringContainsString("'loginEnvironment' => 'app'", $code);
        self::assertStringContainsString("'forgotUrl' => app_url('/recuperar-senha')", $code);
        self::assertStringContainsString('csrf_verify($data)', $code);
        self::assertStringContainsString("request_limit('resident_app_login', 8, 300)", $code);
        self::assertStringContainsString("url('/studio/login/2fa?next=app')", $code);
        self::assertStringNotContainsString("render('pages/login'", $code);
    }

    public function testLoginViewHasResidentCardAndTips(): void
    {
        $html = (string)file_get_contents(self::ROOT . '/container/cms/studio/v1/components/login/login.php');
        self::assertStringContainsString("'app' => ['CONNECT MORADOR', 'Área do morador'", $html);
        self::assertStringContainsString('htmlspecialchars($loginNotice)', $html);
        self::assertStringContainsString('connect-login__note', $html);
        self::assertStringContainsString('csrf_input()', $html);
    }

    public function testRecoveryRouteAndResidentContext(): void
    {
        $routes = (string)file_get_contents(self::ROOT . '/container/app/connect/v1/routes.php');
        self::assertStringContainsString('$route->get("/recuperar-senha", "AppLogin:forgot");', $routes);
        $web = (string)file_get_contents(self::ROOT . '/source/Controllers/Web/Connect/Web.php');
        self::assertStringContainsString('residentRecovery', $web);
        self::assertStringContainsString('!\Source\Support\Access::can("erp.access", $user)', $web);
        self::assertStringContainsString('.connect-login__note{', (string)file_get_contents(self::ROOT . '/container/cms/studio/v1/assets/studio.min.css'));
    }

    public function testResidentScreensUseTheDayPhoto(): void
    {
        $views = self::ROOT . '/container/cms/studio/v1/components/login/';
        foreach (['login', 'forget', 'reset', 'two-factor'] as $view) {
            $html = (string)file_get_contents($views . $view . '.php');
            self::assertStringContainsString("'/assets/images/bkg_login_morador_dia.jpg' : '/assets/images/bkg_home_connect.jpg'", $html, $view);
        }
        self::assertFileExists(self::ROOT . '/container/web/default/v1/assets/images/bkg_login_morador_dia.jpg');
    }

    /** ADR 0680: a tela de entrada cabe na janela (cartão compacto em telas baixas, coluna flexível no celular). */
    public function testLoginFitsTheWindow(): void
    {
        $min = (string)file_get_contents(self::ROOT . '/container/cms/studio/v1/assets/studio.min.css');
        self::assertStringContainsString('@media (min-width:861px) and (max-height:940px){', $min);
        self::assertStringContainsString('.connect-login__access{flex:1 0 auto;min-height:0', $min);
    }
}
