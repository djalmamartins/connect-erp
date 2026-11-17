<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Source\Services\Help\ArticleRenderer;
use Source\Services\Help\KnowledgeBase;
use Source\Support\RichHtml;

/** HTML do Studio limpo antes do site público (ADR 0346). */
final class RichHtmlTest extends TestCase
{
    public static function attacks(): array
    {
        return [
            'script' => ['<p>ok</p><script>alert(1)</script>'],
            'evento' => ['<img src="/a.png" onerror="alert(1)">'],
            'evento maiúsculo' => ['<p ONCLICK="alert(1)">x</p>'],
            'link javascript' => ['<a href="javascript:alert(1)">x</a>'],
            'link com espaço e entidade' => ['<a href=" jav&#x09;ascript:alert(1)">x</a>'],
            'imagem data' => ['<img src="data:image/svg+xml,<svg onload=alert(1)>">'],
            'svg' => ['<svg><script>alert(1)</script></svg>'],
            'iframe de fora' => ['<iframe src="https://evil.example/x"></iframe>'],
            'iframe javascript' => ['<iframe src="javascript:alert(1)"></iframe>'],
            'style com url' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'formulário' => ['<form action="https://evil.example"><input name="senha"><button>ok</button></form>'],
            'textarea fecha editor' => ['</textarea><script>alert(1)</script>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'],
            'objeto' => ['<object data="x.swf"></object><embed src="x.swf">'],
            'comentário condicional' => ['<!--[if IE]><script>alert(1)</script><![endif]-->'],
            'srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>" src="https://www.youtube.com/embed/x"></iframe>'],
        ];
    }

    #[DataProvider('attacks')]
    public function testDangerousMarkupIsRemoved(string $html): void
    {
        $clean = RichHtml::clean($html);

        self::assertDoesNotMatchRegularExpression('/<(script|svg|form|input|object|embed|meta|style)\b/i', $clean);
        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $clean);
        self::assertDoesNotMatchRegularExpression('/(javascript|data|vbscript):/i', html_entity_decode($clean));
        self::assertStringNotContainsString('srcdoc', $clean);
        self::assertStringNotContainsString('evil.example', $clean);
        self::assertSame($clean, RichHtml::clean($clean), 'limpar de novo não muda nada');
    }

    public function testOrdinaryFormattingIsKept(): void
    {
        $html = '<h2 id="t">Título</h2><p style="text-align: center">Texto <strong>forte</strong>, <em>itálico</em> e <a href="https://connectcondominios.com.br/x" target="_blank">link</a>.</p>'
            . '<ul><li>um</li></ul><table><tr><td colspan="2">c</td></tr></table><img src="/storage/images/a.jpg" alt="A">'
            . '<iframe src="https://www.youtube.com/embed/abc" allowfullscreen></iframe><a href="mailto:x@y.z">e-mail</a>';
        $clean = RichHtml::clean($html);

        foreach (['<h2 id="t">', 'style="text-align: center"', '<strong>forte</strong>', '<em>itálico</em>', 'href="https://connectcondominios.com.br/x"', 'rel="noopener noreferrer"', '<li>um</li>', 'colspan="2"', 'src="/storage/images/a.jpg"', 'src="https://www.youtube.com/embed/abc"', 'sandbox=', 'href="mailto:x@y.z"'] as $piece) {
            self::assertStringContainsString($piece, $clean);
        }
    }

    public function testUnknownTagKeepsItsTextEscaped(): void
    {
        self::assertSame('<p>a b &lt;c&gt;</p>', RichHtml::clean('<p><font color="red">a</font> <blink>b</blink> &lt;c&gt;</p>'));
        self::assertSame('', RichHtml::clean(null));
    }

    /** Os artigos da Central de Ajuda saem iguais: só a forma das entidades pode mudar. */
    public function testHelpCenterArticlesSurviveUntouched(): void
    {
        $renderer = new ArticleRenderer(static fn (string $slug): string => '/suporte/a/' . $slug, static fn (string $id): string => '/s/' . $id . '.jpg');
        $count = 0;
        foreach ((new KnowledgeBase())->articles() as $slug => $article) {
            $html = $renderer->render($article['body'])['html'];
            $normal = static fn (string $value): string => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            self::assertSame($normal($html), $normal(RichHtml::clean($html)), $slug);
            $count++;
        }
        self::assertGreaterThan(100, $count);
    }

    /** Nenhuma tela do site imprime conteúdo do Studio sem passar pela limpeza. */
    public function testPublicTemplatesPrintStudioContentOnlyThroughClean(): void
    {
        $root = dirname(__DIR__, 3) . '/container/web';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        $raw = [];
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) ?: [] as $n => $line) {
                if (preg_match('/<\?=\s*(?:html_entity_decode\()?\$[a-zA-Z]+->content\b/', $line)) {
                    $raw[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $raw);
    }
}
