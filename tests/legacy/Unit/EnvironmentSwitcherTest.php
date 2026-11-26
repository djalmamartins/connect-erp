<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\EnvironmentSwitcher;

/**
 * Alternador de ambientes único (ADR 0627): o ERP, o Studio e o Help Desk usam o mesmo componente,
 * a mesma lista (Source\Services\Environments) e o mesmo comportamento; só a cor muda.
 */
final class EnvironmentSwitcherTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    /** @return list<array{key:string,label:string,description:string,icon:string,href:string,current:bool}> */
    private function items(string $current): array
    {
        $rows = [
            ['studio', 'Studio', 'Gestão de conteúdo e operação'],
            ['erp', 'ERP Connect Condomínios', 'Gestão administrativa'],
            ['helpdesk', 'Help Desk', 'Atendimento e chamados'],
            ['residents', 'Moradores', 'Aplicativo do morador'],
            ['talk', 'Talk', 'Atendimento por WhatsApp'],
            ['site', 'Site', 'Site institucional'],
        ];
        return array_map(static fn (array $row): array => [
            'key' => $row[0], 'label' => $row[1], 'description' => $row[2], 'icon' => '',
            'href' => '/ambiente/' . $row[0], 'current' => $row[0] === $current,
        ], $rows);
    }

    public function testSameMarkupInEverySystemOnlyTheCurrentItemChanges(): void
    {
        $shapes = [];
        foreach (['erp' => ['ERP', 'Connect Condomínios'], 'studio' => ['CMS', 'Moves Studio'], 'helpdesk' => ['Atendimento', 'Moves Help Desk']] as $key => [$kicker, $name]) {
            $html = EnvironmentSwitcher::markup($this->items($key), $key, $kicker, $name);
            self::assertStringContainsString('data-environment="' . $key . '"', $html);
            self::assertStringContainsString('<small>' . $kicker . '</small><strong>' . $name . '</strong>', $html);
            self::assertStringContainsString('Ambientes Connect', $html);
            self::assertSame(6, substr_count($html, 'class="cx-envswitch__item'));
            self::assertSame(1, substr_count($html, 'aria-current="page"'));
            self::assertSame(1, substr_count($html, '<em>Atual</em>'));
            self::assertMatchesRegularExpression('~class="cx-envswitch__item is-current" href="/ambiente/' . $key . '" aria-current="page"~', $html);
            self::assertStringContainsString('aria-expanded="false"', $html);
            self::assertStringContainsString(' hidden>', $html);
            // Tirando o ambiente atual e o texto do botão, o HTML é o mesmo nos três sistemas.
            $shapes[] = preg_replace(['~data-environment="' . $key . '"~', '~<small>[^<]*</small><strong>[^<]*</strong></span><svg class="cx-envswitch__chevron~', '~ is-current~', '~ aria-current="page"~', '~<em>Atual</em>~'], ['', '', '', '', ''], $html, 1);
        }
        self::assertCount(1, array_unique(array_map(static fn (?string $shape): string => (string)preg_replace('~ is-current| aria-current="page"|<em>Atual</em>~', '', (string)$shape), $shapes)));
    }

    public function testEscapesTextAndLinks(): void
    {
        $html = EnvironmentSwitcher::markup([[
            'key' => 'erp', 'label' => 'ERP <b>', 'description' => 'x" onclick="y', 'icon' => '', 'href' => '/a?b=1&c="2"', 'current' => true,
        ]], 'erp', '<i>', 'Nome & Cia');
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringNotContainsString('" onclick="', $html);
        self::assertStringContainsString('href="/a?b=1&amp;c=&quot;2&quot;"', $html);
        self::assertStringContainsString('<small>&lt;i&gt;</small><strong>Nome &amp; Cia</strong>', $html);
    }

    public function testEveryEnvironmentHasItsOwnIcon(): void
    {
        $html = EnvironmentSwitcher::markup($this->items('erp'), 'erp', 'ERP', 'Connect Condomínios');
        preg_match_all('~<svg class="cx-envswitch__icon"[^>]*>(.*?)</svg>~s', $html, $icons);
        self::assertCount(6, $icons[1]);
        self::assertCount(6, array_unique($icons[1]));
    }

    public function testErpStudioAndHelpDeskUseTheSharedComponent(): void
    {
        $erp = (string)file_get_contents(self::ROOT . '/container/erp/connect/v1/components/sidebar/sidebar.php');
        $studio = (string)file_get_contents(self::ROOT . '/container/cms/studio/v1/layouts/studio.php');
        self::assertStringContainsString("EnvironmentSwitcher::render(\$user, 'erp', 'ERP', 'Connect Condomínios')", $erp);
        self::assertStringContainsString("EnvironmentSwitcher::render(\$user, \$isHelpDeskEnvironment ? 'helpdesk' : 'studio'", $studio);
        // O desenho antigo de cada sistema saiu.
        self::assertStringNotContainsString('erp-envswitch__panel', $erp);
        self::assertStringNotContainsString('studio-system-panel', $studio);
        self::assertStringNotContainsString('Environments::menu(', $erp . $studio);
        foreach (['container/erp/connect/v1/default.php', 'container/cms/studio/v1/layouts/studio.php'] as $layout) {
            $source = (string)file_get_contents(self::ROOT . '/' . $layout);
            self::assertStringContainsString('/container/shared/assets/envswitch/envswitch.css', $source, $layout);
            self::assertStringContainsString('/container/shared/assets/envswitch/envswitch.js', $source, $layout);
        }
    }

    public function testEachSystemKeepsItsColour(): void
    {
        $css = (string)file_get_contents(self::ROOT . '/container/shared/assets/envswitch/envswitch.css');
        self::assertStringContainsString('--cx-env-accent: #07884c', $css);
        self::assertStringContainsString('.cx-envswitch[data-environment="studio"] { --cx-env-accent: #7c22d4', $css);
        self::assertStringContainsString('.cx-envswitch[data-environment="helpdesk"] { --cx-env-accent: #2563eb', $css);
        self::assertStringContainsString('.cx-envswitch__item.is-current', $css);
        $js = (string)file_get_contents(self::ROOT . '/container/shared/assets/envswitch/envswitch.js');
        foreach (['Escape', 'ArrowDown', 'aria-expanded', 'document.body.appendChild(panel)'] as $needle) {
            self::assertStringContainsString($needle, $js);
        }
    }
}
