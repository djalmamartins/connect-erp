<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\ImportScreens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\Imports\Imports;

/**
 * Telas de importação (AP-140, AP-142 a AP-146 e AP-044; ADR 0058 e 0074): uma página por tipo, tela
 * própria só com dados, modelo de planilha, envio pela API com CSRF e a fila de linhas do extrato.
 */
final class ErpImportScreensTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testEveryImportKindHasItsPage(): void
    {
        $catalog = require dirname(__DIR__, 3) . '/' . self::THEME . 'components/layout/catalog.php';
        $permissions = Panel::pagePermissions();
        foreach (ImportScreens::pages() as $page => $kind) {
            self::assertSame($page, Imports::KINDS[$kind]['page']);
            self::assertArrayHasKey($page, $catalog['pages'], "{$page} precisa estar no catálogo");
            self::assertSame('erp.imports.view', $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame('pages/imports/import', Panel::ownTemplate($page, ['importReady' => true]));
        }
        self::assertCount(count(Imports::KINDS), ImportScreens::pages());
    }

    public function testTemplateHeaderListsRequiredThenOptional(): void
    {
        self::assertSame('natureza;descricao;valor;competencia;vencimento;conta;pago_em;conta_bancaria;unidade;documento;fornecedor', ImportScreens::templateHeader('entries'));
        self::assertSame('unidade;fracao', ImportScreens::templateHeader('fractions'));
        self::assertNull(ImportScreens::templateHeader('ofx'));
        self::assertNull(ImportScreens::templateHeader('nope'));
        // NF-e (AP-141, ADR 0086 e 0079): página com permissão de importação e a conta de despesa enviada na prévia.
        self::assertSame('erp.imports.view', Panel::pagePermissions()['AP-141']);
        $view = $this->file(self::THEME . 'pages/imports/import.php');
        self::assertStringContainsString('name="chart_account_id" required', $view);
        self::assertStringContainsString("body.append('chart_account_id', expense.value)", $this->file(self::THEME . 'assets/js/erp.js'));
        self::assertStringContainsString("body.append('due_on', dueOn.value)", $this->file(self::THEME . 'assets/js/erp.js'));
        self::assertSame(1_000_000, ImportScreens::maxBytes('nfe'));
        self::assertSame(Imports::MAX_BYTES, ImportScreens::maxBytes('entries'));
        self::assertSame('Conferidas', ImportScreens::appliedLabel('matched'));
        self::assertSame('outro', ImportScreens::appliedLabel('outro'));
    }

    public function testUploadApplyAndResolveGoThroughTheApi(): void
    {
        $view = $this->file(self::THEME . 'pages/imports/import.php');
        self::assertStringContainsString('csrf_input()', $view);
        self::assertStringContainsString("url('/api/v1/imports/preview')", $view);
        self::assertStringContainsString("url('/api/v1/imports/__ID__/apply')", $view);
        self::assertStringContainsString("'/api/v1/bank-statement-lines/' . (int)\$line['id'] . '/resolve'", $view);
        self::assertStringContainsString('data-form-error aria-live="assertive"', $view);
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString("\$\$('[data-erp-imports]')", $js);
        self::assertStringContainsString("sendMultipart(root.getAttribute('data-preview-api'), csrf()", $js);
        self::assertStringContainsString("{ action: 'match', entry_id: parseInt(choice.value, 10)", $js);
        $candidates = $this->file('source/Services/Erp/Imports/StatementLineCandidates.php');
        self::assertStringContainsString('$this->scope->canAccess($user, (int)$line[\'condominium_id\'])', $candidates);
        self::assertStringContainsString('AND l.id IS NULL', $candidates, 'candidato já conciliado fica de fora');
    }
}
