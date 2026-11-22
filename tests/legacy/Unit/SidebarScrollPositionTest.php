<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Menu lateral mantém a rolagem entre páginas (ADR 0636, pedido do Djalma em 22/11): ao clicar em
 * Relatórios > Rateio, a página nova abre com o menu na mesma posição e o item ativo à vista.
 */
final class SidebarScrollPositionTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testErpSidebarKeepsScrollInTheTab(): void
    {
        foreach (['container/erp/connect/v1/assets/js/erp.js', 'container/erp/connect/v1/assets/erp.min.js'] as $script) {
            $js = $this->file($script);
            self::assertStringContainsString('keepNavPosition', $js, $script);
            self::assertStringContainsString('erp.sidebar.scroll', $js, $script);
            self::assertStringContainsString('sessionStorage', $js, $script);
            self::assertStringContainsString('pagehide', $js, $script);
        }
        $js = $this->file('container/erp/connect/v1/assets/js/erp.js');
        self::assertStringNotContainsString("active.scrollIntoView", $js, 'rolar só o menu, não a página');
    }

    public function testActiveGroupStillOpensOnTheServer(): void
    {
        $sidebar = $this->file('container/erp/connect/v1/components/sidebar/sidebar.php');
        self::assertStringContainsString("<?= \$isActive ? ' open' : '' ?>", $sidebar);
    }

    public function testStudioAndHelpDeskSidebarKeepScrollPerEnvironment(): void
    {
        $layout = $this->file('container/cms/studio/v1/layouts/studio.php');
        self::assertStringContainsString("'studio-menu-scroll-'+(document.body.dataset.environment||'studio')", $layout);
        self::assertStringContainsString("nav.querySelector('a[aria-current=\"page\"]')", $layout);
        self::assertStringContainsString("window.addEventListener('pagehide',save)", $layout);
    }
}
