<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\ManagementFeeScreens;

/** Tela Honorários (CN-008, ADR 0402): rotas antes do catálogo, permissões, CSRF e o botão só com a chave ligada. */
final class ErpManagementFeeScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testRoutesCatalogAndApi(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['ManagementFeeScreens::ROUTE, "ManagementFeeScreens:index"', '"/condominios/honorarios/{id}", "ManagementFeeScreens:save"',
            '"/condominios/honorarios/{id}/nota", "ManagementFeeScreens:request"', '"/condominios/honorarios/pedidos/{id}/cancelar", "ManagementFeeScreens:cancel"'] as $needle) {
            self::assertStringContainsString($needle, $routes);
        }
        self::assertLessThan(strpos($routes, '$route->get("/condominios/{id}", "Condominiums:show")'), strpos($routes, 'ManagementFeeScreens:index'));
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        self::assertSame(ManagementFeeScreens::ROUTE, $catalog['pages'][ManagementFeeScreens::PAGE_ID]['route']);
        $api = $this->file('index.php');
        foreach (['"/management-fees", "ManagementFees:index"', '"/condominiums/{id}/nfse-requests", "ManagementFees:request"', '"/nfse-requests/{id}/cancel", "ManagementFees:cancel"'] as $needle) {
            self::assertStringContainsString($needle, $api);
        }
    }

    public function testPermissionsCsrfAndSwitch(): void
    {
        $controller = $this->file('source/Controllers/Erp/Connect/ManagementFeeScreens.php');
        self::assertStringContainsString('csrf_verify($data)', $controller);
        self::assertStringContainsString("Access::can('erp.management_fees.manage', \$user)", $controller);
        $view = $this->file('container/erp/connect/v1/pages/condominiums/management-fees.php');
        self::assertStringContainsString('$canManage && $enabled &&', $view, 'Sem a chave ligada, o botão Solicitar nota não aparece.');
        self::assertSame(substr_count($view, '<form method="post"'), substr_count($view, 'csrf_input()'));
        // Cadastrar/Abrir usam ?editar=, nunca ?condominio= (filtro da barra superior, que some da URL).
        self::assertStringContainsString("'editar' => \$id", $view);
        self::assertStringNotContainsString("'condominio' => \$id", $view);
        self::assertStringNotContainsString("'?condominio='", $controller);
        $migration = $this->file('database/migrations/20261114_1100_management_fees_nfse.sql');
        self::assertStringContainsString("'erp.management_fees.view'", $migration);
        self::assertStringContainsString('uq_erp_nfse_requests_open', $migration);
    }
}
