<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Tela das faturas (AP-032, ADR 0033): lê $invoices e $canManageInvoices de Panel::invoiceData e
 * faz as ações pela API /api/v1/invoices, com o CSRF no cabeçalho.
 */
final class ErpInvoicesScreenTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testRealDataUsesTheOwnTemplateAndDemoStaysAsFallback(): void
    {
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("\$id === 'AP-032' && (\$data['invoices'] ?? null) !== null ? 'pages/finance/invoices'", $panel);
    }

    public function testViewOnlyOffersWritesToManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/finance/invoices.php');
        foreach (['$invoices', "url('/api/v1/invoices')", 'csrf_input()', 'name="reason" minlength="5" maxlength="255" required', 'name="competencia"', 'name="situacao"'] as $needle) {
            self::assertStringContainsString($needle, $view);
        }
        self::assertStringContainsString("\$canManageInvoices && \$status === 'draft'", $view, 'emitir só em preparação');
        self::assertStringContainsString("\$canManageInvoices && \$status !== 'cancelled'", $view, 'cancelar fora das canceladas');
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-invoices]', "'/items/'", "'/issue'", "'/cancel'", "call('PATCH'", "call('POST', api, body)", 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
