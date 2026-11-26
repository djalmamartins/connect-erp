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
        self::assertSame(3, substr_count($controller, 'csrf_verify($data)'), 'acompanhamento, nova proposta e gerar/enviar');
        self::assertStringContainsString('(new ProposalDesk())->update(', $controller);
        $studio = $this->file('source/Controllers/Studio/Studio.php');
        self::assertStringNotContainsString('ProposalDesk())->update(', $studio, 'ADR 0650: o Studio não grava mais.');
        self::assertStringNotContainsString('ProposalPdf', $studio, 'ADR 0650: o Studio não gera PDF.');
        self::assertStringNotContainsString('new Email()', substr($studio, (int)strpos($studio, 'public function proposal('), 3000), 'ADR 0650: o Studio não envia proposta.');
        self::assertStringNotContainsString("r.slug IN ('developer','super_admin','client_admin','manager')", $studio, 'Responsável pela permissão, não por lista de papéis.');
        $detail = $this->file('container/cms/studio/v1/components/proposals/detail.php');
        self::assertStringNotContainsString('<form', $detail, 'Studio só consulta.');
        self::assertStringContainsString('Abrir no ERP', $detail);
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
        foreach (['"/proposals", "Proposals:index"', '"/proposals/{id}", "Proposals:show"', '"/proposals/{id}/status", "Proposals:status"',
            '$route->post("/proposals", "Proposals:store"', '"/proposals/{id}/responses", "Proposals:respond"'] as $needle) {
            self::assertStringContainsString($needle, $index);
        }
        $spec = $this->file('docs/api/openapi.yaml');
        foreach (['operationId: listProposals', 'operationId: getProposal', 'operationId: setProposalStatus', 'operationId: createProposal', 'operationId: createProposalResponse'] as $needle) {
            self::assertStringContainsString($needle, $spec);
        }
        self::assertStringContainsString('ProposalDesk::allowed($user)', $this->file('source/Controllers/Api/V1/Proposals.php'));
    }

    public function testErpRoutesForCreateGenerateAndSend(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        $show = strpos($routes, 'ProposalScreens::ROUTE . "/{id}", "ProposalScreens:show"');
        foreach (['"/nova", "ProposalScreens:create"', '"/nova", "ProposalScreens:store"', '"/{id}/pdf/{response}", "ProposalScreens:pdf"', '"/{id}/resposta", "ProposalScreens:respond"'] as $needle) {
            self::assertNotFalse(strpos($routes, $needle), $needle);
            self::assertLessThan($show, strpos($routes, $needle), $needle . ' vem antes de /{id}.');
        }
        self::assertStringContainsString("data-erp-no-confirm", $this->file('container/erp/connect/v1/assets/js/erp.js'));
        self::assertStringContainsString("data-erp-no-confirm", $this->file('container/erp/connect/v1/assets/erp.min.js'), 'erp.min.js regerado');
    }

    public function testDefaultsFollowTheConnectModel(): void
    {
        $defaults = \Source\Services\Proposals\ProposalComposer::defaults(['name' => 'Ana', 'condominium' => 'Bloco 08']);
        self::assertSame('syndic', $defaults['template_type']);
        self::assertStringContainsString('Cuidamos de toda a gestão administrativa', $defaults['introduction']);
        self::assertCount(10, explode("\n", $defaults['scope']));
        self::assertSame(date('Y-m-d', strtotime('+20 days')), $defaults['valid_until']);
        self::assertSame('administrator', \Source\Services\Proposals\ProposalComposer::defaults([], 'administrator')['template_type']);
        self::assertSame('syndic', \Source\Services\Proposals\ProposalComposer::defaults([], 'x')['template_type']);
        self::assertSame('Telefone', \Source\Services\Proposals\ProposalComposer::originLabel('telefone'));
        self::assertStringStartsWith('Site', \Source\Services\Proposals\ProposalComposer::originLabel('site'));
    }

    public function testPdfTemplateHasTheModelPagesAndNoStockPhotos(): void
    {
        $template = $this->file('source/Support/Proposal/templates/connect.php');
        foreach (['Conteúdo', 'Sobre a', 'Conheça nossos', 'Cronograma de', 'Mapa de serviços', 'Formalização', 'Resolva', '43.346.552/0001-69'] as $needle) {
            self::assertStringContainsString($needle, $template);
        }
        self::assertDoesNotMatchRegularExpression('/\.(jpe?g|webp)\b/i', $template, 'sem fotos de banco de imagens (licença)');
        self::assertStringNotContainsString('DIFERENCIAS', mb_strtoupper($template));
        self::assertStringContainsString('$h($proposal->condominium)', $template, 'dados da proposta escapados');
        foreach (['Regular', 'SemiBold', 'Bold'] as $weight) {
            self::assertFileExists(dirname(__DIR__, 3) . '/source/Support/Proposal/fonts/Montserrat-' . $weight . '.ttf');
        }
        self::assertFileExists(dirname(__DIR__, 3) . '/source/Support/Proposal/fonts/OFL.txt');
    }
}
