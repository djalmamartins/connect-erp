<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Help\ArticleRenderer;
use Source\Services\Help\Icons;
use Source\Services\Help\KnowledgeBase;

/** Central de Ajuda (ADR 0141): formato dos artigos, Markdown seguro, prints marcados e cobertura do menu. */
final class HelpCenterKnowledgeBaseTest extends TestCase
{
    private function renderer(): ArticleRenderer
    {
        return new ArticleRenderer(static fn (string $slug): ?string => '/suporte/x/' . $slug, static fn (string $id): ?string => '/kb/' . $id . '.jpg');
    }

    public function testEveryMenuPageHasAValidArticle(): void
    {
        $base = new KnowledgeBase();
        $codes = array_keys(KnowledgeBase::erpCatalog()['pages']);
        self::assertNotEmpty($codes);
        // Sem exigir os JPGs: no CI eles podem não estar gerados; o formato e a cobertura, sim.
        self::assertSame([], $base->problems($codes, false));
    }

    public function testParseReadsFrontmatterAndScreens(): void
    {
        $article = KnowledgeBase::parse(<<<'MD'
---
codigo: AP-010, AP-011
titulo: Unidades
resumo: Cadastre e consulte as unidades do condomínio escolhido.
categoria: condominios
rota: /cadastros/unidades
relacionados: proprietarios-e-inquilinos
rascunho: sim
---

## Onde fica

```tela unidades-lista
rota: /cadastros/unidades
condominio: residencial-jardim-das-acacias
legenda: Lista de unidades
antes: clicar main button:has-text("Nova")
recorte: main form
1: menu | Menu lateral
2: main table thead | Colunas
```
MD);
        self::assertSame(['AP-010', 'AP-011'], $article['codes']);
        self::assertSame('Unidades', $article['title']);
        self::assertTrue($article['draft']);
        self::assertSame(['proprietarios-e-inquilinos'], $article['related']);
        self::assertCount(1, $article['screens']);
        $screen = $article['screens'][0];
        self::assertSame('unidades-lista', $screen['id']);
        self::assertSame('/cadastros/unidades', $screen['route']);
        self::assertSame('main form', $screen['frame']);
        self::assertSame('residencial-jardim-das-acacias', $screen['options']['condominio']);
        self::assertSame(['clicar main button:has-text("Nova")'], $screen['before']);
        self::assertSame([1, 2], array_column($screen['marks'], 'n'));
    }

    public function testRendererEscapesHtmlAndUnsafeLinks(): void
    {
        $out = $this->renderer()->render("## Título <script>x</script>\n\nTexto <img src=x onerror=alert(1)> e [clique](javascript:alert(1)) e [ok](artigo:unidades).");
        self::assertStringNotContainsString('<script>', $out['html']);
        self::assertStringNotContainsString('<img src=x', $out['html']);
        self::assertStringNotContainsString('href="javascript:', $out['html']);
        self::assertStringContainsString('href="/suporte/x/unidades"', $out['html']);
        // O índice guarda o texto puro; a página escapa ao exibir (pages/article.php).
        self::assertSame('Título <script>x</script>', $out['toc'][0]['title'] ?? null);
        self::assertStringContainsString('Título &lt;script&gt;', $out['html']);
    }

    public function testRendererMarksMenuPathsButtonsCalloutsAndScreens(): void
    {
        $out = $this->renderer()->render(<<<'MD'
## Como cadastrar

Abra [[Cadastros > Unidades]] e clique em {{Nova unidade}}.

> Dica útil.

>! Cuidado.

>> Tudo sobre [Unidades](artigo:unidades)

```tela unidades-novo
rota: /cadastros/unidades/novo
legenda: Formulário de nova unidade
1: main form input | Primeiro campo
```
MD);
        $html = $out['html'];
        self::assertStringContainsString('class="kb-path"', $html);
        self::assertStringContainsString('class="kb-button"', $html);
        self::assertStringContainsString('kb-callout--warning', $html);
        self::assertStringContainsString('class="kb-more"', $html);
        self::assertStringContainsString('<figure class="kb-shot"', $html);
        self::assertStringContainsString('/kb/unidades-novo.jpg', $html);
        self::assertStringContainsString('Formulário de nova unidade', $html);
        self::assertSame('como-cadastrar', $out['toc'][0]['id'] ?? null);
    }

    public function testProblemsFlagBrokenArticles(): void
    {
        $root = sys_get_temp_dir() . '/kb-test-' . bin2hex(random_bytes(4));
        mkdir($root . '/artigos/geral', 0777, true);
        file_put_contents($root . '/categorias.json', json_encode([['chave' => 'geral', 'titulo' => 'Geral', 'descricao' => 'Teste', 'icone' => 'list']]));
        file_put_contents($root . '/artigos/geral/Ruim.md', "---\ntitulo: X\nresumo: curto\ncategoria: outra\nrelacionados: nao-existe\n---\n\nPouco texto.\n");
        try {
            $problems = implode("\n", (new KnowledgeBase($root))->problems(['AP-999'], false));
            self::assertStringContainsString('slug', $problems);
            self::assertStringContainsString('titulo', $problems);
            self::assertStringContainsString('resumo', $problems);
            self::assertStringContainsString("categoria 'outra'", $problems);
            self::assertStringContainsString("relacionado 'nao-existe'", $problems);
            self::assertStringContainsString('muito curto', $problems);
            self::assertStringContainsString('AP-999', $problems);
        } finally {
            unlink($root . '/artigos/geral/Ruim.md');
            unlink($root . '/categorias.json');
            rmdir($root . '/artigos/geral');
            rmdir($root . '/artigos');
            rmdir($root);
        }
    }

    public function testIconsAreStaticAndFallBack(): void
    {
        self::assertTrue(Icons::has('home-outline'));
        self::assertFalse(Icons::has('nao-existe'));
        self::assertSame(Icons::svg('help-circle'), Icons::svg('<script>'));
        self::assertStringContainsString('class="hc-icon x&quot;y"', Icons::svg('home', 'x"y'));
    }
}
