<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\EntryShortcutScreens;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas dos lançamentos avulsos e do recibo (AP-024 a AP-029 e AP-040, ADR 0057 e 0073): rotas,
 * permissões, telas próprias só com dados e gravação pela API REST com CSRF.
 */
final class ErpEntryShortcutScreensTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testNewExpenseAndIncomeOpenThePayableAndReceivableForms(): void
    {
        $catalog = require dirname(__DIR__, 3) . '/' . self::THEME . 'components/layout/catalog.php';
        self::assertSame($catalog['pages']['AP-024']['route'], array_keys(EntryShortcutScreens::NEW_ENTRY_ROUTES)[0]);
        self::assertSame($catalog['pages']['AP-025']['route'], array_keys(EntryShortcutScreens::NEW_ENTRY_ROUTES)[1]);
        self::assertSame($catalog['pages']['AP-022']['route'] . '/novo', EntryShortcutScreens::NEW_ENTRY_ROUTES['/financeiro/lancar-despesa']);
        self::assertSame($catalog['pages']['AP-023']['route'] . '/novo', EntryShortcutScreens::NEW_ENTRY_ROUTES['/financeiro/lancar-receita']);
        $routes = $this->file(self::THEME . 'routes.php');
        self::assertStringContainsString('EntryShortcutScreens::NEW_ENTRY_ROUTES as $erpFrom => $erpTo', $routes);
        self::assertStringContainsString("isset(\\Source\\Controllers\\Erp\\Connect\\EntryShortcutScreens::NEW_ENTRY_ROUTES[\$page['route']])", $routes);
    }

    public function testPermissionsFollowTheApi(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (['AP-024', 'AP-025', 'AP-026', 'AP-029'] as $page) {
            self::assertSame('erp.entries.view', $permissions[$page]);
        }
        self::assertSame('erp.invoices.view', $permissions['AP-027']);
        self::assertSame('erp.invoices.view', $permissions['AP-028']);
        self::assertSame('erp.unit_documents.view', $permissions['AP-040']);
        self::assertSame(['AP-027' => 'fine', 'AP-028' => 'extra'], EntryShortcutScreens::CHARGE_PAGES);
        self::assertSame(array_keys(\Source\Services\Erp\EntryShortcuts::CHARGE_KINDS), ['extra', 'fine']);
    }

    public function testOwnScreensOnlyWithData(): void
    {
        foreach (EntryShortcutScreens::PAGES as $page => $template) {
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame($template, Panel::ownTemplate($page, ['shortcutReady' => true]));
            self::assertFileExists(dirname(__DIR__, 3) . '/' . self::THEME . $template . '.php');
        }
        self::assertSame('AP-040', Panel::RECEIPT_PAGE);
        self::assertSame('pages/documents/unit-document', Panel::ownTemplate('AP-040', ['unitDocuments' => ['data' => []]]));
        self::assertArrayHasKey('receipt', \Source\Services\Erp\UnitDocuments::ALL_KINDS);
    }

    public function testOptionsForContext(): void
    {
        $options = [['id' => 1, 'condominium_id' => null, 'label' => 'Padrão'], ['id' => 2, 'condominium_id' => 7, 'label' => 'Do 7'], ['id' => 3, 'condominium_id' => 8, 'label' => 'Do 8']];
        self::assertSame([1, 2], array_column(EntryShortcutScreens::forContext($options, 7), 'id'));
        self::assertSame([], EntryShortcutScreens::forContext($options, null));
    }

    public function testWritesGoThroughTheApiWithCsrf(): void
    {
        foreach (['pages/finance/discount.php', 'pages/finance/extra-charges.php', 'pages/finance/bank-transfers.php'] as $page) {
            $html = $this->file(self::THEME . $page);
            self::assertStringContainsString('data-erp-shortcuts', $html);
            self::assertStringContainsString('csrf_input()', $html);
            self::assertStringContainsString('data-form-error aria-live="assertive"', $html . $this->file(self::THEME . 'components/cards/shortcut-cancel.php'));
        }
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString("\$\$('[data-erp-shortcuts]')", $js);
        self::assertStringContainsString('body.entry_ids = receiptBoxes', $js);
        self::assertStringContainsString("'X-CSRF-Token': csrf()", $js);
        $receipt = $this->file('source/Services/Erp/ReceiptCandidates.php');
        self::assertStringContainsString('$this->scope->canAccess($user, (int)$condominium)', $receipt);
        self::assertStringContainsString("d.status = 'issued'", $receipt);
    }
}
