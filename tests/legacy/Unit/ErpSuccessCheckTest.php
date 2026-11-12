<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Aviso de sucesso com a marca virando check (ADR 0120, pedido do Djalma em 11/11/2026): toca uma vez só,
 * vale para o flash de sucesso do servidor e para ErpUI.toast(..., 'success').
 */
final class ErpSuccessCheckTest extends TestCase
{
    private const ASSETS = 'container/erp/connect/v1/assets/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    private function icon(): string
    {
        $js = $this->file(self::ASSETS . 'js/erp.js');
        self::assertSame(1, preg_match("/var DONE_ICON = '(<svg.*?<\\/svg>)';/", $js, $match), 'ícone do aviso embutido no erp.js');

        return $match[1];
    }

    public function testIconPlaysOnceAndStopsOnTheCheck(): void
    {
        $icon = $this->icon();
        self::assertStringNotContainsString('repeatCount', $icon, 'a animação não repete');
        self::assertStringNotContainsString('indefinite', $icon);
        $animations = preg_match_all('/<(animate|animateTransform|animateMotion|set) /', $icon);
        self::assertSame($animations, substr_count($icon, 'fill="freeze"'), 'toda animação para no último quadro (o check)');
        self::assertStringContainsString('aria-hidden="true"', $icon, 'o texto do aviso é que é lido, não o ícone');

        $svg = simplexml_load_string(str_replace('<svg ', '<svg xmlns="http://www.w3.org/2000/svg" ', $icon));
        self::assertNotFalse($svg, 'SVG bem formado');
    }

    public function testSplineAnimationsEndAtKeyTimeOne(): void
    {
        // Com calcMode="spline", o último keyTimes precisa ser 1; senão o navegador ignora a animação.
        preg_match_all('/<animate(?:Transform)? [^>]*calcMode="spline"[^>]*>/', $this->icon(), $splines);
        self::assertNotSame([], $splines[0]);
        foreach ($splines[0] as $tag) {
            self::assertMatchesRegularExpression('/keyTimes="[^"]*;1"/', $tag);
            preg_match('/keyTimes="([^"]*)"/', $tag, $times);
            preg_match('/keySplines="([^"]*)"/', $tag, $curves);
            self::assertCount(count(explode(';', $times[1])) - 1, explode(';', $curves[1]));
        }
    }

    public function testServerSuccessFlashAndSuccessToastsUseTheNotice(): void
    {
        $js = $this->file(self::ASSETS . 'js/erp.js');
        self::assertStringContainsString("$('.erp-flash .message.success')", $js, 'flash de sucesso do servidor vira o aviso');
        self::assertStringContainsString("if (tone === 'success' && !(options && options.quiet)) { done(message); return; }", $js);
        self::assertStringContainsString("toast('Copiado: ' + label + '.', 'success', { quiet: true })", $js, 'cópia não é gravação');
        self::assertStringContainsString('window.ErpUI = { toast: toast, done: done };', $js);
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $js, 'movimento reduzido mostra o check parado');

        $layout = $this->file('container/erp/connect/v1/default.php');
        self::assertStringContainsString('data-erp-toasts role="status" aria-live="polite"', $layout, 'o aviso entra numa região lida pelo leitor de tela');
        self::assertStringContainsString('<div class="erp-flash" role="status">', $layout, 'sem JavaScript a faixa continua');

        $css = $this->file(self::ASSETS . 'css/erp.css');
        self::assertStringContainsString('.erp-done {', $css);
        self::assertStringContainsString('.erp-done--leaving {', $css);
    }
}
