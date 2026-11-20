<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\RichHtml;

/**
 * Moves Editor no lugar do TinyMCE e do Organic Editor (ADR 0610, pedido do Djalma em 20/11/2026).
 * O componente fica em container/shared/assets/moves-editor e o servidor continua limpando o HTML.
 */
final class MovesEditorTest extends TestCase
{
    private const DIR = 'container/shared/assets/moves-editor';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function file(string $path): string
    {
        return (string)file_get_contents($this->root() . '/' . $path);
    }

    public function testOldEditorsAreGone(): void
    {
        foreach (['container/shared/assets/vendor/scripts/tinymce', 'container/cms/studio/v1/assets/vendor/organic-editor', 'container/cms/studio/v1/assets/js/editor.js'] as $path) {
            self::assertFileDoesNotExist($this->root() . '/' . $path);
        }
        $found = [];
        foreach (['container/cms', 'container/erp', 'container/app', 'container/web', 'container/send', 'source'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string)$file);
                if (!preg_match('/\.(php|js|css)$/', $path) || str_contains($path, '/vendor/')) {
                    continue;
                }
                if (preg_match('/tinymce|tinyMCE|data-organic-editor|MovesOrganicEditor|mce_upload|mce_image|organic-editor\.min/', (string)file_get_contents($path))) {
                    $found[] = substr($path, strlen($this->root()) + 1);
                }
            }
        }
        self::assertSame([], $found, 'sobrou código do editor antigo');
    }

    public function testStudioLoadsTheSharedEditor(): void
    {
        $layout = $this->file('container/cms/studio/v1/layouts/studio.php');
        foreach (['moves-editor.css', 'moves-editor.js', 'moves-editor-boot.js'] as $asset) {
            self::assertStringContainsString('/container/shared/assets/moves-editor/' . $asset, $layout);
        }
        foreach ([
            'container/cms/studio/v1/components/blog/post.php' => 'post-content',
            'container/cms/studio/v1/components/pages/form.php' => 'page-content',
            'container/cms/studio/v1/components/slides/form.php' => 'slide-content',
            'container/cms/studio/v1/components/support/article.php' => 'support-content',
        ] as $form => $id) {
            $html = $this->file($form);
            self::assertMatchesRegularExpression('/<textarea data-moves-editor="full"[^>]*id="' . $id . '"/', $html, $form);
            self::assertStringContainsString('data-media-editor="' . $id . '"', $html, $form . ': botão da biblioteca de mídia');
        }
        self::assertStringContainsString('window.MovesEditors?.insert(editorId, html)', $this->file('container/cms/studio/v1/assets/js/scripts.js'));
        self::assertStringNotContainsString('tinymce', strtolower($this->file('container/cms/studio/v1/assets/studio.min.js')), 'studio.min.js regerado');
    }

    public function testEditorFixesFromConnect(): void
    {
        $js = $this->file(self::DIR . '/moves-editor.js');
        // Digitar não reescreve o conteúdo (na v1.0.0 cada tecla mandava o cursor para o começo).
        self::assertStringContainsString("}else{this.scrub();this.source.value=this.serializeHTML();", $js);
        self::assertStringNotContainsString('this.editor.innerHTML=this.sanitizeHTML(this.editor.innerHTML)', $js);
        self::assertStringNotContainsString('onclick="', $js, 'sem onclick inline no HTML do editor');
        self::assertDoesNotMatchRegularExpression('/(?<![\w.])alert\(/', $js, 'avisos no próprio editor, sem alert');
        self::assertStringContainsString("this.sanitizeHTML(doc.body.innerHTML)", $js, 'colagem sanitizada');
        self::assertStringContainsString("toolbar:'full',html:true", $js);
        $css = $this->file(self::DIR . '/moves-editor.css');
        self::assertDoesNotMatchRegularExpression('/(^|\})\s*(body|\*|\.badge|\.hero|\.demo-[\w-]+)\s*\{/', $css, 'sem estilos da página de demonstração');
        self::assertStringNotContainsString(':root', $css);
        $boot = $this->file(self::DIR . '/moves-editor-boot.js');
        self::assertStringContainsString("textarea[data-moves-editor]", $boot);
        self::assertStringContainsString("data.append('csrf', csrfToken(textarea.form));", $boot, 'envio de imagem com CSRF');
        self::assertStringContainsString("(wrappingLabel || textarea).insertAdjacentElement('afterend', host);", $boot);
    }

    public function testServerKeepsEditorFormattingAndDropsTheRest(): void
    {
        $clean = RichHtml::clean('<figure data-align="center" style="width:50%"><img src="/storage/images/a.jpg" alt="A"><figcaption>Legenda</figcaption></figure><p><span style="font-size:24px;color:rgb(239, 68, 68)">grande</span></p>');
        self::assertStringContainsString('<figure data-align="center" style="width: 50%">', $clean);
        self::assertStringContainsString('<span style="font-size: 24px; color: rgb(239, 68, 68)">grande</span>', $clean);
        self::assertStringContainsString('<figcaption>Legenda</figcaption>', $clean);
        self::assertStringNotContainsString('data-align', RichHtml::clean('<figure data-align="javascript:x"><img src="/a.jpg"></figure>'));
    }

    public function testEditorImageUploadAcceptsContentEditors(): void
    {
        $controller = $this->file('source/Controllers/Studio/Studio.php');
        self::assertStringContainsString('foreach (["media.manage", "articles.manage", "pages.manage", "slides.manage", "support.manage"] as $permission)', $controller);
        self::assertStringNotContainsString('function postImage', $controller);
        self::assertStringNotContainsString('function supportImage', $controller);
    }
}
