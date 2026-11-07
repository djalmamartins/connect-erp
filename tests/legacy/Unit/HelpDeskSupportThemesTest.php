<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Garante que Help Desk (/helpdesk) e Central de Suporte (/suporte) seguem
 * o contrato de tema Organic Code: raiz do tema apenas com default.php e
 * routes.php, telas em pages/ e layout em layouts/.
 */
final class HelpDeskSupportThemesTest extends TestCase
{
    public function testHelpDeskResolvesToCmsTheme(): void
    {
        $path = moves_container_path('helpdesk', 'default');
        self::assertStringEndsWith('/container/cms/helpdesk/v1', $path);
        self::assertFileExists($path . '/routes.php');
        self::assertStringEndsWith('/container/cms/helpdesk/v1/assets/x.css', moves_container_url('helpdesk', 'default', '/assets/x.css'));
    }

    public function testSupportResolvesToOwnWebTheme(): void
    {
        foreach (['support', 'support_by_moves'] as $alias) {
            self::assertStringEndsWith('/container/web/support/v1', moves_container_path('web', $alias));
        }
        $root = moves_container_path('web', 'support');
        foreach (['pages/home', 'pages/search', 'pages/category', 'pages/article', 'pages/widget', 'layouts/theme'] as $template) {
            self::assertFileExists("{$root}/{$template}.php");
        }
        self::assertFileExists($root . '/assets/support.css');
        self::assertFileExists($root . '/assets/images/helpdesk-mark.svg');
    }

    public function testThemeRootsContainOnlyContractPhpFiles(): void
    {
        foreach ([moves_container_path('helpdesk', 'default'), moves_container_path('web', 'support')] as $root) {
            $php = array_map('basename', glob($root . '/*.php') ?: []);
            sort($php);
            self::assertSame(['default.php', 'routes.php'], $php, $root);
        }
    }

    public function testSupportPagesAreNoLongerInsideDefaultWebTheme(): void
    {
        $default = moves_container_path('web', 'default');
        self::assertDirectoryDoesNotExist($default . '/support');
        self::assertFileDoesNotExist($default . '/_support.php');
    }

    public function testRoutesDeclareExpectedEndpoints(): void
    {
        $support = (string)file_get_contents(moves_container_path('web', 'support') . '/routes.php');
        foreach (['"/widget/abrir", "Support:widgetCreate"', '"/widget/acompanhar", "Support:widgetLookup"', '"/{section}/{article}", "Support:article"'] as $route) {
            self::assertStringContainsString($route, $support);
        }
        $helpDesk = (string)file_get_contents(moves_container_path('helpdesk', 'default') . '/routes.php');
        self::assertStringContainsString('$route->group("/helpdesk")', $helpDesk);
        foreach (['Studio:tickets', 'Studio:agenda', 'Studio:support', 'Studio:supportArticle', 'Studio:login'] as $action) {
            self::assertStringContainsString($action, $helpDesk);
            self::assertTrue(method_exists(\Source\Controllers\Studio\Studio::class, explode(':', $action)[1]), $action);
        }
    }

    public function testWidgetScriptPointsToVersionedSupportAssets(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/container/shared/assets/js/helpdesk-widget.js');
        self::assertStringContainsString('/container/web/support/v1/assets/images/helpdesk-mark.svg', $script);
    }
}
