<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\RichText;

/** Mensagens do Moves Editor (barra "basic"): guardar, mostrar e virar texto puro (ADR 0610). */
final class RichTextTest extends TestCase
{
    public function testStoreKeepsBasicFormattingAndDropsTheRest(): void
    {
        $stored = RichText::store('<p>Olá <strong>João</strong></p><ul><li>um</li></ul><p><a href="https://x.com/a" target="_blank">link</a></p>'
            . '<img src="/a.jpg" onerror="alert(1)"><table><tr><td>t</td></tr></table><h1>Título</h1><script>alert(1)</script><p onclick="x()">fim</p>');
        foreach (['<strong>João</strong>', '<li>um</li>', 'href="https://x.com/a"', 'rel="noopener noreferrer"', 'Título', '<p>fim</p>'] as $piece) {
            self::assertStringContainsString($piece, $stored);
        }
        foreach (['<img', '<table', '<h1', '<script', 'onclick', 'onerror'] as $piece) {
            self::assertStringNotContainsString($piece, $stored);
        }
        self::assertSame('', RichText::store('<p>&nbsp;</p><p><br></p>'), 'parágrafo vazio do editor conta como vazio');
        self::assertSame('texto a < b', RichText::store('  texto a < b '), 'texto sem marcação segue a regra antiga (strip_tags)');
    }

    public function testOldPlainTextStaysReadable(): void
    {
        $canary = '"\'></script><i data-xss>x';
        self::assertSame(htmlspecialchars($canary, ENT_QUOTES, 'UTF-8'), RichText::html($canary), 'texto antigo com "<" sai escapado');
        self::assertSame($canary, RichText::plain($canary));
        self::assertSame('<p><strong>Olá</strong> mundo</p>', RichText::store('<strong>Olá</strong> mundo'), 'o que sai do editor começa com bloco');
        self::assertSame("linha &lt;1&gt;<br />\nduas", RichText::html("linha <1>\nduas"));
        self::assertSame("linha <1>\nduas", RichText::plain("linha <1>\nduas"));
    }

    public function testPlainTextForWhatsAppPushAndApp(): void
    {
        $plain = RichText::plain('<p>Olá <strong>João</strong>,</p><ul><li>um</li><li>dois</li></ul><p>veja <a href="https://x.com/a">aqui</a> &amp; mais<br>fim</p>');
        self::assertSame("Olá João,\n\n• um\n• dois\nveja aqui (https://x.com/a) & mais\nfim", $plain);
        self::assertStringNotContainsString('<', $plain);
    }

    public function testHtmlIsCleanEvenWhenTheDatabaseHasSomethingBad(): void
    {
        $html = RichText::html('<p>ok</p><img src=x onerror=alert(1)><a href="javascript:alert(1)">x</a>');
        self::assertStringNotContainsString('onerror', $html);
        self::assertStringNotContainsString('javascript:', $html);
        self::assertStringNotContainsString('<img', $html);
    }
}
