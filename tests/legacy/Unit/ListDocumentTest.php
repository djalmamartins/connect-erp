<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\ListDocument;
use Source\Support\Erp\RegistryExport;
use ZipArchive;

/** Download das listas em PDF e Excel no padrão do modelo 3 (revisão de 15/11 item 18, ADR 0314). */
final class ListDocumentTest extends TestCase
{
    private const TABLE = ['header' => ['Unidade', 'Proprietário', 'Saldo'],
        'rows' => [['Sala 501', '<b>Ana & Cia</b>', "'-10,50"], ['Loja 01', "'=HYPERLINK(\"x\")", '1234,56']]];

    public function testRulesForFormatTitleNameAndCells(): void
    {
        self::assertSame(['pdf', 'xlsx', 'xlsx', 'csv', null, null], array_map([ListDocument::class, 'format'], ['PDF', 'excel', 'xlsx', 'csv', 'html', null]));
        self::assertSame('Unidades', ListDocument::title(null, 'Unidades'));
        self::assertSame('Inadimplentes - de 01/10', ListDocument::title("  <script>Inadimplentes</script>\n - de 01/10 ", 'x'));
        self::assertSame(120, mb_strlen(ListDocument::title(str_repeat('á', 300), 'x')));
        self::assertSame('contas-a-pagar-2026-11-16.pdf', ListDocument::filename('Contas a pagar', 'pdf', '2026-11-16'));
        self::assertSame('balanco-2026.xlsx', ListDocument::rename('balanco-2026.csv', 'xlsx'));
        self::assertSame(['-10,50', "=HYPERLINK(\"x\")", "'texto"], [ListDocument::plain("'-10,50"), ListDocument::plain("'=HYPERLINK(\"x\")"), ListDocument::plain("'texto")]);
        self::assertSame([-10.5, 1234.56, 42, null, null, null], array_map([ListDocument::class, 'number'], ['-10,50', '1234,56', '42', '1.234,56', '01/10/2026', '0012']));
        $this->expectException(InvalidArgumentException::class);
        ListDocument::render('html', self::TABLE, ['title' => 'x']);
    }

    public function testPdfHasConnectHeaderEscapedCellsAndPages(): void
    {
        $html = ListDocument::html(self::TABLE, ['title' => 'Unidades', 'condominium' => 'Alpha (Lafaiete / CENTRO)', 'date' => '16/11/2026']);
        foreach (['CONNECT CONDOMÍNIOS LTDA', 'CNPJ: 43.346.552/0001-69', '16/11/2026', 'Alpha (Lafaiete / CENTRO)', '>Unidades<', '&lt;b&gt;Ana &amp; Cia&lt;/b&gt;',
            '<td class="num">-10,50</td>', '<th class="num">Saldo</th>', 'display:table-header-group'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
        self::assertStringNotContainsString('<b>Ana', $html);
        self::assertStringContainsString('Todos os condomínios', ListDocument::html(['header' => ['A'], 'rows' => []], ['title' => 'T']));
        self::assertStringContainsString('Nenhum registro.', ListDocument::html(['header' => ['A'], 'rows' => []], ['title' => 'T']));
        $pdf = ListDocument::render('pdf', self::TABLE, ['title' => 'Unidades']);
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    public function testXlsxHasHeaderLinesNumbersAndNoFormulas(): void
    {
        $content = ListDocument::render('xlsx', self::TABLE, ['title' => 'Unidades: lista/2026', 'condominium' => 'Alpha', 'date' => '16/11/2026']);
        $path = (string)tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path) === true);
        $sheet = (string)$zip->getFromName('xl/worksheets/sheet1.xml');
        $book = (string)$zip->getFromName('xl/workbook.xml');
        $zip->close();
        unlink($path);
        self::assertStringContainsString('name="Unidades  lista 2026"', $book);
        self::assertStringContainsString('<t xml:space="preserve">CNPJ: 43.346.552/0001-69</t>', $sheet);
        self::assertStringContainsString('<c r="C10" s="2"><v>-10.5</v></c>', $sheet);
        self::assertStringContainsString('<c r="C11" s="2"><v>1234.56</v></c>', $sheet);
        self::assertStringContainsString('&lt;b&gt;Ana &amp; Cia&lt;/b&gt;', $sheet);
        self::assertStringNotContainsString('<f>', $sheet, 'Texto nunca vira fórmula.');
        self::assertStringContainsString('ySplit="9"', $sheet, 'Cabeçalho da tabela fica congelado.');
        self::assertSame(ListDocument::render('csv', self::TABLE, ['title' => 'x']), CollectionReports::csv(self::TABLE), 'CSV é o de sempre.');
    }

    public function testHideUnitDropsTheUnitColumnFromTheFile(): void
    {
        $table = ['header' => ['Data', 'Unidade', 'Descrição', 'Valor'], 'rows' => [['01/10/2026', 'Apto 101', 'Taxa', '200,00'], ['02/10/2026', 'Apto 102', 'Taxa', '300,00']]];
        self::assertSame(['header' => ['Data', 'Descrição', 'Valor'], 'rows' => [['01/10/2026', 'Taxa', '200,00'], ['02/10/2026', 'Taxa', '300,00']]], ListDocument::withoutUnit($table));
        $csv = ListDocument::render('csv', $table, ['title' => 'x', 'hide_unit' => true]);
        self::assertStringNotContainsString('Apto', $csv);
        self::assertStringContainsString('Apto 101', ListDocument::render('csv', $table, ['title' => 'x', 'hide_unit' => false]));
        self::assertStringNotContainsString('Apto', ListDocument::html(ListDocument::withoutUnit($table), ['title' => 'x']));
    }

    public function testRegistryTableUsesListColumnsMoneyAsNumberAndSafeCells(): void
    {
        $definition = ['title' => 'name', 'status' => ['active' => 'Ativo', 'inactive' => 'Inativo'],
            'fields' => ['name' => ['label' => 'Nome', 'type' => 'text', 'list' => false], 'amount' => ['label' => 'Valor', 'type' => 'money', 'list' => true],
                'hidden' => ['label' => 'Oculto', 'type' => 'text', 'list' => false], 'condominium_id' => ['label' => 'Condomínio', 'type' => 'condominium', 'list' => true]],
            'people' => ['roles' => ['owner' => 'owners'], 'labels' => ['owner' => 'Proprietário']]];
        $table = RegistryExport::table($definition, [['name' => '=1+1', 'amount' => 123456, 'condominium_id' => 2, 'condominium_name' => 'Alpha', 'owners' => ['Ana', 'Bia'], 'status' => 'active'],
            ['name' => 'B', 'amount' => null, 'condominium_id' => null, 'owners' => [], 'status' => 'inactive']]);
        self::assertSame(['Nome', 'Valor', 'Condomínio', 'Proprietário', 'Situação'], $table['header']);
        self::assertSame([["'=1+1", '1234,56', 'Alpha', 'Ana, Bia', 'Ativo'], ['B', '', 'Todos', '', 'Inativo']], $table['rows']);
    }
}
