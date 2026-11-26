<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0655: "Esqueci minha senha" e "Criar senha nova" usam a mesma moldura do login
 * (layouts/login do Studio) e não as views antigas do site, que ficavam sem layout no ERP.
 */
final class PasswordRecoveryViewTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const VIEWS = self::ROOT . '/container/cms/studio/v1/components/login/';

    public function testControllerRendersLoginFrameViews(): void
    {
        $code = (string)file_get_contents(self::ROOT . '/source/Controllers/Web/Connect/Web.php');
        self::assertStringContainsString('render("components/login/forget"', $code);
        self::assertStringContainsString('render("components/login/reset"', $code);
        self::assertStringNotContainsString('render("pages/login/forget"', $code);
        self::assertStringNotContainsString('render("pages/login/reset"', $code);
        self::assertStringNotContainsString('$json["redirect"] = url("/studio/login")', $code, 'Depois da senha nova, voltar ao login de origem.');
    }

    /** @dataProvider views */
    public function testViewUsesLoginLayoutAndCard(string $view, string $action): void
    {
        $html = (string)file_get_contents(self::VIEWS . $view . '.php');
        self::assertStringContainsString("\$this->layout('layouts/login'", $html);
        self::assertStringContainsString('connect-login__card', $html);
        self::assertStringContainsString("components/login/_recovery-visual", $html);
        self::assertStringContainsString('csrf_input()', $html);
        self::assertStringContainsString("url('" . $action . "')", $html);
        self::assertStringContainsString('htmlspecialchars($loginBack)', $html);
    }

    public static function views(): array
    {
        return [['forget', '/forget'], ['reset', '/reset']];
    }

    public function testResetEscapesToken(): void
    {
        $html = (string)file_get_contents(self::VIEWS . 'reset.php');
        self::assertStringContainsString('value="<?= htmlspecialchars($code) ?>"', $html);
    }
}
