<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas dos documentos da unidade (AP-123/124/125/130, ADR 0044 e 0046): dados de
 * Panel::unitDocumentData, emissão, conferência e cancelamento pela API /api/v1/unit-documents.
 */
final class ErpUnitDocumentsScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testEachPageIssuesItsOwnKindAndUsesTheSharedTemplate(): void
    {
        self::assertSame(['debt_certificate', 'delinquency_declaration', 'quittance', 'consent_letter'], array_values(Panel::UNIT_DOCUMENT_PAGES));
        self::assertSame(array_keys(\Source\Services\Erp\UnitDocuments::KINDS), array_values(Panel::UNIT_DOCUMENT_PAGES));
        foreach (array_keys(Panel::UNIT_DOCUMENT_PAGES) as $page) {
            self::assertNull(Panel::ownTemplate($page, ['unitDocuments' => null]), "$page sem dados segue a demonstração");
            self::assertSame('pages/documents/unit-document', Panel::ownTemplate($page, ['unitDocuments' => ['data' => []]]));
        }
    }

    public function testUnitOptionsFollowTheContextAndTheScope(): void
    {
        $units = [['id' => 1, 'condominium_id' => 2, 'label' => 'B'], ['id' => 2, 'condominium_id' => 1, 'label' => 'Z'], ['id' => 3, 'condominium_id' => 1, 'label' => 'A'], ['id' => 4, 'condominium_id' => 9, 'label' => 'X']];
        $names = [1 => 'Aurora', 2 => 'Bosque'];
        self::assertSame([3, 2, 1], array_column(Panel::documentUnits($units, $names, null), 'id'), 'por condomínio e unidade; fora do escopo some');
        self::assertSame([1], array_column(Panel::documentUnits($units, $names, 2), 'id'), 'só o condomínio em contexto');
        self::assertSame('Bosque', Panel::documentUnits($units, $names, 2)[0]['condominium_name']);
        self::assertSame([], Panel::documentUnits($units, $names, 9), 'contexto fora do escopo não lista nada');
    }

    public function testWritesOnlyForManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/documents/unit-document.php');
        self::assertStringContainsString('<?php if ($canManageDocuments): ?>', $view);
        self::assertStringContainsString('$canManageDocuments && $active', $view);
        self::assertStringContainsString('$canManageDocuments && !$cancelled', $view);
        self::assertStringContainsString('name="reason" minlength="5" maxlength="255" required', $view);
        self::assertStringContainsString('csrf_input()', $view);
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("!Access::can('erp.unit_documents.view', \$user)", $panel);
        self::assertStringContainsString("Access::can('erp.unit_documents.manage', \$user)", $panel);
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-unit-documents]', "'/verify/'", "'/cancel'", 'until_competence', 'purpose', '?documento=', '[data-erp-print]', 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
