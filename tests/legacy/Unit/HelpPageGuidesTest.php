<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Help\KnowledgeBase;
use Source\Services\Help\PageGuides;

/** "Sobre esta tela" e "O que acontece por trás" (ADR 0309): uma fonte para o ERP e para o artigo. */
final class HelpPageGuidesTest extends TestCase
{
    private const GUIDE = ['faz' => 'Cadastra as unidades do condomínio, uma a uma.', 'por_tras' => ['Não repete a unidade.', 'Tudo vai para o histórico.'], 'mais' => 'Um exemplo.'];

    public function testGuidesAreValidAndArticlesAreInSync(): void
    {
        $codes = array_keys(KnowledgeBase::erpCatalog()['pages']);
        self::assertSame([], PageGuides::problems(new KnowledgeBase(), $codes));
        // Etapa 1 (Dashboard, Condomínios e Cadastros) inteira tem explicação.
        $catalog = KnowledgeBase::erpCatalog();
        foreach ($catalog['pages'] as $code => $page) {
            if (in_array($page['group'], ['dashboard', 'condominios', 'cadastros'], true)) {
                self::assertNotNull(PageGuides::for($code), "{$code} sem explicação");
            }
        }
    }

    public function testApplyInsertsBeforeWhoCanUseAndReplacesInPlace(): void
    {
        $source = "---\ntitulo: X\n---\n\nTexto.\n\n## Como cadastrar\n\n1. Passo.\n\n## Quem pode usar\n\nTodos.\n";
        $once = PageGuides::apply($source, self::GUIDE);
        self::assertStringContainsString("1. Passo.\n\n## O que acontece por trás\n\nQuando você usa esta tela, o sistema faz o seguinte:\n\n- Não repete a unidade.\n- Tudo vai para o histórico.\n\nUm exemplo.\n\n## Quem pode usar", $once);
        self::assertSame($once, PageGuides::apply($once, self::GUIDE), 'aplicar de novo não muda nada');

        $changed = PageGuides::apply($once, ['por_tras' => ['Outra regra.'], 'mais' => ''] + self::GUIDE);
        self::assertSame(1, substr_count($changed, '## O que acontece por trás'));
        self::assertStringContainsString("- Outra regra.\n\n## Quem pode usar", $changed);
        self::assertStringNotContainsString('Não repete a unidade.', $changed);
    }

    public function testApplyAppendsWhenThereIsNoWhoCanUseAndIgnoresCodeFences(): void
    {
        $source = "Texto.\n\n```tela x\nrota: /x\n## Quem pode usar\n```\n";
        $out = PageGuides::apply($source, self::GUIDE);
        self::assertStringEndsWith("Um exemplo.\n", $out);
        self::assertStringContainsString("```\n\n## O que acontece por trás", $out);
    }

    public function testIntroIsShownOnTheErpPageWithTheHelpLink(): void
    {
        $guide = PageGuides::for('AP-006');
        self::assertNotNull($guide);
        self::assertGreaterThanOrEqual(30, mb_strlen($guide['faz']));
        self::assertNull(PageGuides::for(null));
        self::assertNull(PageGuides::for('XX-999'));
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/page-intro.php');
        self::assertStringContainsString("/suporte/tela/", $template);
        self::assertStringContainsString('data-erp-intro', $template);
        self::assertStringContainsString("insert('components/layout/page-intro')", (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/page-head.php'));
    }

    public function testEachStageOneAreaHasAnIntroOnTheHelpCenter(): void
    {
        $kb = new KnowledgeBase();
        self::assertSame([], $kb->areaProblems());
        foreach (['primeiros-passos', 'condominios', 'cadastros'] as $key) {
            self::assertNotNull($kb->areaIntro($key), "área {$key} sem resumo");
        }
        self::assertNull($kb->areaIntro('../segredo'));
        self::assertNull($kb->areaIntro('nao-existe'));
        self::assertStringContainsString('areaIntro(', (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/support/v1/pages/category.php'));
    }
    public function testIntroCanBeTurnedOffInSettingsAndClosedWithTheX(): void
    {
        $spec = \Source\Services\Erp\ErpSettings::CATALOG['admin.page_intro'];
        self::assertSame(['AP-147', 'bool', true, 'global'], [$spec['page'], $spec['type'], $spec['default'], $spec['scope']]);
        self::assertFalse(\Source\Services\Erp\ErpSettings::normalize('admin.page_intro', '0'));
        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/page-intro.php');
        self::assertStringContainsString("'admin.page_intro'", $template);
        self::assertStringContainsString('data-erp-intro-close', $template);
        self::assertStringContainsString("'dismissed'", (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/assets/js/erp.js'));
    }

    /** ADR 0625: o quadro em todas as páginas do menu, sempre fechado ao abrir a tela. */
    public function testEveryMenuPageHasTheIntroAndItAlwaysStartsClosed(): void
    {
        $codes = array_keys(KnowledgeBase::erpCatalog()['pages']);
        self::assertSame(count($codes), PageGuides::coverage($codes), 'toda página do menu tem o quadro');
        foreach ($codes as $code) {
            $intro = PageGuides::forPage($code);
            self::assertNotNull($intro, "{$code} sem o quadro");
            self::assertGreaterThanOrEqual(20, mb_strlen($intro['faz']), "{$code} com texto curto");
            foreach ($intro['topicos'] as $topic) {
                self::assertMatchesRegularExpression('/^[a-z0-9-]+$/', $topic['id']);
                self::assertNotContains($topic['title'], ['Onde fica', 'Quem pode usar']);
            }
        }
        // Escrita à mão vale mais que o resumo do artigo; tela sem nada não mostra quadro.
        self::assertSame(PageGuides::for('AP-004')['faz'], PageGuides::forPage('AP-004')['faz']);
        self::assertNotSame([], PageGuides::forPage('AP-004')['por_tras']);
        self::assertNull(PageGuides::forPage('XX-999'));
        self::assertNull(PageGuides::forPage(null));
        // O arquivo gerado está igual ao que os artigos geram hoje.
        self::assertSame(
            str_replace("\r\n", "\n", (string)file_get_contents(PageGuides::SUMMARIES_FILE)),
            PageGuides::buildSummaries(new KnowledgeBase(), $codes)
        );

        $template = (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/page-intro.php');
        self::assertStringContainsString('PageGuides::forPage(', $template);
        self::assertMatchesRegularExpression('/<details class="erp-page-intro__box">/', $template, 'nasce fechada: <details> sem open');
        self::assertStringNotContainsString('<details class="erp-page-intro__box" open', $template);
        $js = (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/assets/js/erp.js');
        self::assertStringNotContainsString("state === 'open'", $js, 'não reabre sozinha quem abriu antes');
        self::assertStringNotContainsString('details.open = true', $js);
    }
}
