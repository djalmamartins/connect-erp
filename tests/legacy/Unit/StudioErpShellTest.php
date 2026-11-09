<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Studio e Help Desk usam a moldura do ERP (ADR 0026) e o Studio responde no host próprio.
 */
final class StudioErpShellTest extends TestCase
{
    private ?string $host = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = $_SERVER['HTTP_HOST'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->host === null) {
            unset($_SERVER['HTTP_HOST']);
        } else {
            $_SERVER['HTTP_HOST'] = $this->host;
        }
        parent::tearDown();
    }

    public function testStudioHostIsRecognizedOnlyForStudioSubdomain(): void
    {
        foreach (['studio.connect.lab' => true, 'studio.connect.lab:8443' => true, 'www.connect.lab' => false, 'erp.connect.lab' => false, 'connect.lab' => false, 'mystudio.connect.lab' => false] as $host => $expected) {
            $_SERVER['HTTP_HOST'] = $host;
            self::assertSame($expected, studio_host(), $host);
        }
    }

    public function testSiteHomeLeavesTheStudioHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'studio.connect.lab';
        self::assertStringStartsWith('http', site_home_url());
        self::assertStringContainsString('://www.connect.lab/', site_home_url());
    }

    public function testLayoutUsesErpShellAndLoadsTheVisualLayer(): void
    {
        $root = moves_container_path('studio', 'default');
        $layout = (string)file_get_contents($root . '/layouts/studio.php');
        self::assertStringContainsString('studio-erp', $layout);
        self::assertStringContainsString('/assets/css/connect-shell.css', $layout);
        self::assertStringContainsString('moves-logo-white.svg', $layout);
        self::assertFileExists($root . '/assets/images/moves-logo-white.svg');
        self::assertStringNotContainsString('studio-theme-toggle', $layout);
        self::assertStringNotContainsString('studio-logo.svg', $layout);
        self::assertFileExists($root . '/assets/css/connect-shell.css');
    }

    public function testVisualLayerMirrorsErpStructure(): void
    {
        $erp = (string)file_get_contents(moves_container_path('erp', CONF_VIEW_ERP) . '/assets/css/erp.css');
        $shell = (string)file_get_contents(moves_container_path('studio', 'default') . '/assets/css/connect-shell.css');
        foreach (['font', 'sidebar-width', 'sidebar-collapsed', 'navbar-height'] as $token) {
            preg_match('/--erp-' . preg_quote($token, '/') . ':\s*([^;]+);/', $erp, $erpValue);
            preg_match('/--cx-' . preg_quote($token, '/') . ':\s*([^;]+);/', $shell, $shellValue);
            self::assertNotEmpty($erpValue[1] ?? '', $token);
            self::assertSame(trim($erpValue[1]), trim($shellValue[1] ?? ''), "medida $token igual à do ERP");
        }
    }

    public function testEachEnvironmentHasItsOwnPalette(): void
    {
        $shell = (string)file_get_contents(moves_container_path('studio', 'default') . '/assets/css/connect-shell.css');
        $palette = static function (string $environment) use ($shell): string {
            preg_match('/body\.studio-v2\.studio-erp\[data-environment="' . $environment . '"\]\s*\{([^}]*)\}/', $shell, $block);
            return $block[1] ?? '';
        };
        self::assertStringContainsString('--cx-brand: #6b1fa8', $palette('studio'), 'Studio em roxo');
        self::assertStringContainsString('--cx-brand: #2563eb', $palette('helpdesk'), 'Help Desk em azul');
        foreach (['studio', 'helpdesk'] as $environment) {
            foreach (['--cx-sidebar:', '--cx-canvas:', '--cx-brand-soft:', '--cx-sidebar-accent:'] as $token) {
                self::assertStringContainsString($token, $palette($environment), "$environment define $token");
            }
        }
    }

    public function testStudioHostRegistersOnlyStudioHelpDeskAndRecovery(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertMatchesRegularExpression('/elseif \(studio_host\(\)\) \{.*?routes\.php.*?\} else \{/s', $index);
        preg_match('/elseif \(studio_host\(\)\) \{(.*?)\n\} else \{/s', $index, $branch);
        self::assertStringNotContainsString("moves_container_path('web'", $branch[1] ?? '');
        self::assertStringContainsString("moves_container_path('studio', 'default')", $branch[1] ?? '');
        self::assertStringContainsString("moves_container_path('helpdesk', 'default')", $branch[1] ?? '');
    }

    public function testRecurringEntriesPageOffersMonthlyGeneration(): void
    {
        $root = moves_container_path('erp', CONF_VIEW_ERP);
        $page = (string)file_get_contents($root . '/pages/registry/index.php');
        self::assertStringContainsString("'AP-021'", $page);
        self::assertStringContainsString("url('/api/v1/recurring-entries/generate')", $page);
        self::assertStringContainsString("Access::can('erp.entries.manage')", $page);
        self::assertStringContainsString('csrf_input()', $page);
        $js = (string)file_get_contents($root . '/assets/js/erp.js');
        self::assertStringContainsString('form[data-erp-generate-recurring]', $js);
        self::assertStringContainsString("'X-CSRF-Token'", $js);
    }

    public function testTablesFitTheCardWithoutScrollBarsAboveMobile(): void
    {
        // O admin.css legado fixa min-width de 680px/820px nas tabelas e overflow:auto no invólucro.
        $css = (string)file_get_contents(dirname(__DIR__, 3) . '/container/cms/studio/v1/assets/css/connect-shell.css');
        self::assertStringContainsString('body.studio-erp .studio-table-wrap { overflow-x: auto; overflow-y: hidden', $css);
        self::assertStringContainsString('body.studio-erp .studio-table { min-width: 0 !important', $css);
        self::assertMatchesRegularExpression('~@media \(max-width: 760px\) \{\s*body\.studio-erp \.studio-table \{ min-width: 600px~', $css);
    }
}
