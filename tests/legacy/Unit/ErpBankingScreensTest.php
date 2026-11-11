<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas da cobrança bancária (AP-035 boletos, AP-036 importar retorno, AP-038 divergências; ADR
 * 0035 e 0038): dados de Panel::bankingData, ações pela API /api/v1 com o CSRF no cabeçalho.
 */
final class ErpBankingScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOwnTemplatesOnlyWithRealData(): void
    {
        self::assertNull(Panel::ownTemplate('AP-035', ['bankCharges' => null]));
        self::assertSame('pages/finance/bank-charges', Panel::ownTemplate('AP-035', ['bankCharges' => ['data' => []]]));
        self::assertNull(Panel::ownTemplate('AP-036', ['bankReady' => false]));
        self::assertSame('pages/finance/bank-return-import', Panel::ownTemplate('AP-036', ['bankReady' => true]));
        self::assertSame('pages/finance/bank-divergences', Panel::ownTemplate('AP-038', ['bankDivergences' => []]));
        self::assertNull(Panel::ownTemplate('AP-037', ['bankReady' => true, 'bankRemittances' => null]), 'Sem permissão ou sem banco: segue a demonstração.');
        self::assertSame('pages/finance/bank-remittances', Panel::ownTemplate('AP-037', ['bankRemittances' => ['data' => []]]), 'Remessas ligada (ADR 0156).');
    }

    public function testPanelChecksPermissionsBeforeLoading(): void
    {
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("!Access::can('erp.bank_charges.view', \$user)", $panel);
        self::assertStringContainsString("Access::can('erp.bank_charges.manage', \$user)", $panel);
    }

    public function testViewsOnlyOfferWritesToManagers(): void
    {
        $charges = $this->file('container/erp/connect/v1/pages/finance/bank-charges.php');
        self::assertStringContainsString('$canManageCharges && $open', $charges);
        self::assertStringContainsString("in_array(\$status, ['pending', 'registered'], true)", $charges);
        $divergences = $this->file('container/erp/connect/v1/pages/finance/bank-divergences.php');
        self::assertStringContainsString('name="note" minlength="5" maxlength="255" required', $divergences);
        $remittances = $this->file('container/erp/connect/v1/pages/finance/bank-remittances.php');
        self::assertStringNotContainsString('method="post"', $remittances, 'Remessas é só leitura.');
        self::assertStringContainsString("\$charge['pdf_available']", $charges, 'PDF só quando o banco oferece.');
        foreach (['bank-charges', 'bank-return-import', 'bank-divergences'] as $view) {
            self::assertStringContainsString('csrf_input()', $this->file("container/erp/connect/v1/pages/finance/{$view}.php"), $view);
        }
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-bank-charges]', "'/refresh'", "'/cancel'", '[data-erp-bank-return]', 'readAsText', '[data-erp-bank-divergences]', "'/resolve'", 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
