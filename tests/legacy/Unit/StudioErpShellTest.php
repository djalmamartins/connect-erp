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
        self::assertStringContainsString('site_logo_url()', $layout);
        self::assertStringNotContainsString('studio-theme-toggle', $layout);
        self::assertStringNotContainsString('MOVES<small>', $layout);
        self::assertFileExists($root . '/assets/css/connect-shell.css');
    }

    public function testVisualLayerMirrorsErpTokens(): void
    {
        $erp = (string)file_get_contents(moves_container_path('erp', CONF_VIEW_ERP) . '/assets/css/erp.css');
        $shell = (string)file_get_contents(moves_container_path('studio', 'default') . '/assets/css/connect-shell.css');
        foreach (['brand', 'brand-strong', 'brand-soft', 'sidebar', 'sidebar-ink', 'sidebar-accent', 'canvas', 'border', 'text', 'muted'] as $token) {
            self::assertMatchesRegularExpression('/--erp-' . preg_quote($token, '/') . ':\s*([^;]+);/', $erp, $token);
            preg_match('/--erp-' . preg_quote($token, '/') . ':\s*([^;]+);/', $erp, $erpValue);
            preg_match('/--cx-' . preg_quote($token, '/') . ':\s*([^;]+);/', $shell, $shellValue);
            self::assertSame(trim($erpValue[1]), trim($shellValue[1] ?? ''), "token $token igual ao do ERP");
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
}
