<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Tela da verificação em duas etapas (ADR 0603): mesma moldura do login, dicas de segurança
 * no painel e o código em 6 campos que o login-otp.js junta no campo "code".
 */
final class TwoFactorLoginScreenTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../container/cms/studio/v1/';

    private static function view(): string
    {
        return (string)file_get_contents(self::ROOT . 'components/login/two-factor.php');
    }

    public function testKeepsTheLoginFrameAndShowsSecurityTips(): void
    {
        $view = self::view();
        $login = (string)file_get_contents(self::ROOT . 'components/login/login.php');
        foreach (['connect-login__visual', 'connect-login__pitch', 'connect-login__benefits', 'connect-login__access', 'connect-login__card', 'connect-login__submit', 'Ambiente seguro e monitorado'] as $piece) {
            self::assertStringContainsString($piece, $login, $piece);
            self::assertStringContainsString($piece, $view, $piece);
        }
        self::assertStringContainsString('Nunca compartilhe o código', $view);
        self::assertStringNotContainsString('connect-login--compact', $view);
    }

    public function testCodeHasSixAccessibleDigitsJoinedIntoTheCodeField(): void
    {
        $view = self::view();
        self::assertStringContainsString('for ($i = 1; $i <= 6; $i++)', $view);
        self::assertStringContainsString('aria-label="Dígito <?= $i ?> de 6"', $view);
        self::assertStringContainsString('inputmode="numeric"', $view);
        self::assertStringContainsString("'one-time-code'", $view);
        self::assertStringContainsString('<legend', $view);
        self::assertMatchesRegularExpression('/<input type="hidden" name="code"[^>]*data-otp-value>/', $view);
        // O campo de recuperação não tem name: o valor só chega ao servidor pelo campo "code".
        self::assertDoesNotMatchRegularExpression('/data-recovery-input[^>]*name=|name=[^>]*data-recovery-input/', $view);
        self::assertStringContainsString("login-otp.js", $view);
    }

    public function testScriptHandlesPasteBackspaceAndAutoSubmit(): void
    {
        $js = (string)file_get_contents(self::ROOT . 'assets/js/login-otp.js');
        foreach (['"paste"', '"Backspace"', 'requestSubmit', 'stopImmediatePropagation', 'data-otp-value', '/^\\d{6}$/'] as $piece) {
            self::assertStringContainsString($piece, $js, $piece);
        }
    }

    public function testStylesShipInTheStudioBundle(): void
    {
        $bundle = (string)file_get_contents(self::ROOT . 'assets/studio.min.css');
        self::assertStringContainsString('.connect-login__otp-digit', $bundle);
    }
}
