<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\PrivacyScreens;

/** Tela Dados pessoais (CN-009, ADR 0332): rotas antes do catálogo, busca só por POST, CSRF, permissões e modal. */
final class ErpPrivacyScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testRoutesCatalogAndMenuPermission(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['PrivacyScreens::ROUTE, "PrivacyScreens:index"', 'PrivacyScreens::ROUTE, "PrivacyScreens:search"',
            '"/configuracoes/dados-pessoais/baixar", "PrivacyScreens:download"', '"/configuracoes/dados-pessoais/anonimizar", "PrivacyScreens:anonymize"'] as $needle) {
            self::assertStringContainsString($needle, $routes);
        }
        self::assertLessThan(strpos($routes, 'foreach ($erpCatalog[\'pages\']'), strpos($routes, 'PrivacyScreens:index'));
        self::assertStringContainsString("'/configuracoes/dados-pessoais']", $routes, 'O catálogo não pode registrar a rota de novo.');
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        self::assertSame(PrivacyScreens::ROUTE, $catalog['pages'][PrivacyScreens::PAGE_ID]['route']);
        self::assertSame('erp.privacy.export', Panel::PAGE_PERMISSIONS[PrivacyScreens::PAGE_ID]);
    }

    public function testSearchByPostCsrfAndAnonymizeGuard(): void
    {
        $controller = $this->file('source/Controllers/Erp/Connect/PrivacyScreens.php');
        self::assertSame(3, substr_count($controller, 'csrf_verify($data)'), 'Buscar, baixar e anonimizar conferem o CSRF.');
        self::assertStringContainsString("Access::can('erp.privacy.export', \$user)", $controller);
        self::assertStringContainsString("Access::can('erp.users.manage', \$user)", $controller);
        self::assertStringContainsString("'confirm' => true", $controller);
        self::assertStringNotContainsString('$_GET', $controller, 'CPF e e-mail não podem vir pela URL.');
        $view = $this->file('container/erp/connect/v1/pages/settings/privacy.php');
        self::assertStringNotContainsString('method="get"', $view);
        self::assertSame(substr_count($view, 'method="post"'), substr_count($view, 'csrf_input()'));
        self::assertStringContainsString('data-confirm-undo="nao"', $view, 'Anonimizar avisa que não tem volta.');
        self::assertStringContainsString('$canAnonymize && $privacyPreview !== null', $view);
    }
}
