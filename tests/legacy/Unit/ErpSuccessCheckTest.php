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
        self::assertSame(1, preg_match("/    success: '(<svg.*?<\\/svg>)',/", $js, $match), 'ícone animado do aviso de sucesso embutido no erp.js');

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

    public function testEveryFlashAndToastBecomesATopRightNotice(): void
    {
        $js = $this->file(self::ASSETS . 'js/erp.js');
        self::assertStringContainsString("$$('.erp-flash .message, .erp-main .message[data-notice]').forEach(", $js, 'todo flash do servidor vira aviso no canto');
        self::assertStringContainsString("var NOTICE_TITLES = { success: 'Sucesso!', warning: 'Atenção!', error: 'Erro!', info: 'Informação' };", $js);
        self::assertStringContainsString("if (tone === 'danger' || tone === 'error') return 'error';", $js, 'o tom danger dos toasts vira erro');
        self::assertStringContainsString("var animated = kind === 'success' && !(options && options.quiet);", $js, 'só o sucesso anima; o Copiado fica com o check parado');
        self::assertStringContainsString("toast('Copiado: ' + label + '.', 'success', { quiet: true })", $js);
        self::assertStringContainsString("close.setAttribute('aria-label', 'Fechar aviso');", $js, 'todo aviso tem o X');
        self::assertStringContainsString('timer = window.setTimeout(dismiss,', $js, 'o aviso some sozinho');
        self::assertStringContainsString("'erp-done-mask-' + (++noticeSeq)", $js, 'máscara única por aviso');
        self::assertStringContainsString("matchMedia('(prefers-reduced-motion: reduce)')", $js, 'movimento reduzido mostra o check parado');
        self::assertStringContainsString('window.ErpUI = { toast: toast, done: done };', $js);

        $layout = $this->file('container/erp/connect/v1/default.php');
        self::assertStringContainsString('data-erp-toasts role="status" aria-live="polite"', $layout);
        self::assertStringContainsString('<div class="erp-flash" role="status">', $layout, 'sem JavaScript a faixa continua');

        $css = $this->file(self::ASSETS . 'css/erp.css');
        self::assertStringContainsString('.erp-toasts { position: fixed; top: calc(var(--erp-navbar-height) + 12px); right: 20px;', $css, 'canto superior direito');
        foreach (['success', 'warning', 'error'] as $kind) {
            self::assertStringContainsString('.erp-notice--' . $kind . ' {', $css);
        }
        self::assertStringNotContainsString('.erp-done {', $css, 'o cartão antigo do centro saiu');
    }
}
