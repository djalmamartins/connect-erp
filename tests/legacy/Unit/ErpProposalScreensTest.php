<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\Inbox;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\ProposalScreens;
use Source\Services\Proposals\ProposalDesk;

/** Propostas do site no ERP (CN-011, ADR 0644): rota, menu, permissão, alerta, CSRF, sino e API. */
final class ErpProposalScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testRoutesCatalogMenuAndPermission(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        foreach (['ProposalScreens::ROUTE, "ProposalScreens:index"', 'ProposalScreens::ROUTE . "/p/{page}", "ProposalScreens:index"',
            'ProposalScreens::ROUTE . "/{id}", "ProposalScreens:show"', '$route->post(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/{id}", "ProposalScreens:update"'] as $needle) {
            self::assertStringContainsString($needle, $routes);
        }
        self::assertLessThan(strpos($routes, 'foreach ($erpCatalog[\'pages\']'), strpos($routes, 'ProposalScreens:index'));
        self::assertLessThan(strpos($routes, 'ProposalScreens:show'), strpos($routes, '"/p/{page}", "ProposalScreens:index"'), 'A página /p/N vem antes de /{id}.');
        self::assertStringContainsString("'/comunicacao/propostas', '/configuracoes/dados-pessoais']", $routes, 'O catálogo não pode registrar a rota de novo.');
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        $page = $catalog['pages'][ProposalScreens::PAGE_ID];
        self::assertSame([ProposalScreens::ROUTE, 'comunicacao', 'Comercial'], [$page['route'], $page['group'], $page['section']]);
        self::assertSame(ProposalDesk::PERMISSION, Panel::PAGE_PERMISSIONS[ProposalScreens::PAGE_ID]);
        $first = array_key_first(array_filter($catalog['pages'], static fn (array $p): bool => $p['group'] === 'comunicacao'));
        self::assertSame(ProposalScreens::PAGE_ID, $first, 'Propostas do site é o primeiro item de Comunicação.');
    }

    public function testControllerGuardsCsrfAndSharedService(): void
    {
        $controller = $this->file('source/Controllers/Erp/Connect/ProposalScreens.php');
        self::assertStringContainsString("Access::can('erp.access', \$user) || !ProposalDesk::allowed(\$user)", $controller);
        self::assertSame(1, substr_count($controller, 'csrf_verify($data)'));
        self::assertStringContainsString('(new ProposalDesk())->update(', $controller);
        $studio = $this->file('source/Controllers/Studio/Studio.php');
        self::assertStringContainsString('ProposalDesk())->update(', $studio, 'Studio grava pelo mesmo serviço.');
        self::assertStringNotContainsString("r.slug IN ('developer','super_admin','client_admin','manager')", $studio, 'Responsável pela permissão, não por lista de papéis.');
        $view = $this->file('container/erp/connect/v1/pages/communication/proposal.php');
        self::assertSame(substr_count($view, 'method="post"'), substr_count($view, 'csrf_input()'));
    }

    public function testMenuBadgeAndAlert(): void
    {
        $sidebar = $this->file('container/erp/connect/v1/components/sidebar/sidebar.php');
        self::assertStringContainsString('erp-nav__badge', $sidebar);
        self::assertStringContainsString('$navBadges[$id]', $sidebar);
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("'navBadges' => \$this->navBadges(\$user)", $panel);
        self::assertStringContainsString("\$user->can('proposals.manage')", $panel);
        self::assertStringContainsString("['CN-011' => \$new]", $panel);
        self::assertStringContainsString('data-erp-proposal-alert', $this->file('container/erp/connect/v1/pages/communication/proposals.php'));
    }

    public function testStatusesMatchTheTable(): void
    {
        $schema = $this->file('database/base/schema.sql');
        self::assertStringContainsString("`status` enum('" . implode("','", array_keys(ProposalDesk::STATUSES)) . "')", $schema);
        self::assertSame('Nova', ProposalDesk::label('new'));
    }

    public function testBellLinkKeepsOtherLinks(): void
    {
        $user = new \Source\Models\User();
        self::assertNull(Inbox::erpTarget(null, $user));
        self::assertSame('https://erp.example/operacional/chamados/3', Inbox::erpTarget('https://erp.example/operacional/chamados/3', $user));
        self::assertMatchesRegularExpression('#/studio/proposals/7$#', (string)Inbox::erpTarget('https://studio.example/studio/proposals/7', $user), 'Sem permissão, segue para o Studio.');
    }

    public function testApiRoutesAndOpenApi(): void
    {
        $index = $this->file('index.php');
        foreach (['"/proposals", "Proposals:index"', '"/proposals/{id}", "Proposals:show"', '"/proposals/{id}/status", "Proposals:status"'] as $needle) {
            self::assertStringContainsString($needle, $index);
        }
        $spec = $this->file('docs/api/openapi.yaml');
        foreach (['operationId: listProposals', 'operationId: getProposal', 'operationId: setProposalStatus'] as $needle) {
            self::assertStringContainsString($needle, $spec);
        }
        self::assertStringContainsString('ProposalDesk::allowed($user)', $this->file('source/Controllers/Api/V1/Proposals.php'));
    }
}
