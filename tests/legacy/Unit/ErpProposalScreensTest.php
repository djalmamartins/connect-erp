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
        self::assertSame(5, substr_count($controller, 'csrf_verify($data)'), 'acompanhamento, nova proposta, anexar/enviar, enviar de novo e conta');
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
        foreach (['"/nova", "ProposalScreens:create"', '"/nova", "ProposalScreens:store"', '"/{id}/pdf/{response}", "ProposalScreens:pdf"', '"/{id}/resposta", "ProposalScreens:respond"', '"/{id}/reenviar", "ProposalScreens:resend"', '"/{id}/calculo", "ProposalScreens:quote"'] as $needle) {
            self::assertNotFalse(strpos($routes, $needle), $needle);
            self::assertLessThan($show, strpos($routes, $needle), $needle . ' vem antes de /{id}.');
        }
        self::assertStringContainsString("data-erp-no-confirm", $this->file('container/erp/connect/v1/assets/js/erp.js'));
        self::assertStringContainsString("data-erp-no-confirm", $this->file('container/erp/connect/v1/assets/erp.min.js'), 'erp.min.js regerado');
    }

    public function testDefaultsKindsAndPdfCheck(): void
    {
        $defaults = \Source\Services\Proposals\ProposalComposer::defaults(['name' => 'Ana', 'condominium' => 'Bloco 08']);
        self::assertSame('Proposta comercial Connect Condomínios - Bloco 08', $defaults['subject']);
        self::assertSame(date('Y-m-d', strtotime('+20 days')), $defaults['valid_until']);
        self::assertSame(['administrator' => 'Administradora', 'syndic' => 'Síndico profissional'], \Source\Services\Proposals\ProposalComposer::KINDS);
        self::assertSame('Telefone', \Source\Services\Proposals\ProposalComposer::originLabel('telefone'));
        self::assertStringStartsWith('Site', \Source\Services\Proposals\ProposalComposer::originLabel('site'));
        $tmp = (string)tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, "%PDF-1.4\n%%EOF\n");
        self::assertNull(\Source\Services\Proposals\ProposalComposer::checkPdf(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]));
        file_put_contents($tmp, '<html>%PDF-</html>');
        self::assertSame('anexe um arquivo PDF.', \Source\Services\Proposals\ProposalComposer::checkPdf(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK]));
        self::assertStringContainsString('20 MB', (string)\Source\Services\Proposals\ProposalComposer::checkPdf(['tmp_name' => $tmp, 'error' => UPLOAD_ERR_INI_SIZE]));
        @unlink($tmp);
    }

    public function testUploadComesOnlyFromTheForm(): void
    {
        $controller = $this->file('source/Controllers/Erp/Connect/ProposalScreens.php');
        self::assertStringContainsString('is_uploaded_file($file[\'tmp_name\'])', $controller);
        self::assertStringContainsString('is_uploaded_file($file[\'tmp_name\'])', $this->file('source/Controllers/Api/V1/Proposals.php'));
        $view = $this->file('container/erp/connect/v1/pages/communication/proposal.php');
        self::assertStringContainsString('enctype="multipart/form-data"', $view);
        self::assertStringContainsString('name="pdf_<?= $kind ?>"', $view);
        self::assertFileDoesNotExist(dirname(__DIR__, 3) . '/source/Support/Proposal/ProposalPdf.php', 'o sistema não gera mais o PDF');
    }

    public function testQuoteMath(): void
    {
        $q = \Source\Services\Proposals\ProposalComposer::quote(32, 1300, 500, 50000);
        self::assertSame([41600, 16000, 57600, 691200, true], [$q['admin_monthly'], $q['syndic_monthly'], $q['monthly'], $q['yearly'], $q['worth']]);
        $q = \Source\Services\Proposals\ProposalComposer::quote(32, 1300, null, 50000);
        self::assertSame([41600, null, 41600, false], [$q['admin_monthly'], $q['syndic_monthly'], $q['monthly'], $q['worth']]);
        $q = \Source\Services\Proposals\ProposalComposer::quote(32, null, null, null);
        self::assertSame([null, null, null], [$q['monthly'], $q['yearly'], $q['worth']]);
    }

    public function testOnlyAdministrationAndDevelopmentKeepThePermission(): void
    {
        $migration = $this->file('database/migrations/20261126_0900_proposals_origin.sql');
        self::assertStringContainsString("WHERE r.slug NOT IN ('developer', 'super_admin', 'client_admin')", $migration);
        self::assertStringContainsString("p.slug = 'proposals.manage'", $migration);
    }
}
