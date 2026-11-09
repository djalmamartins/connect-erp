<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Imports\ImportRules;
use Source\Services\Erp\Imports\OfxParser;
use Source\Services\Erp\Imports\SpreadsheetReader;
use Source\Services\Erp\Invoices;
use ZipArchive;

/** Revisão de segurança das importações (ADR 0063): valores sem estouro e XLSX sem explosão de memória. */
final class ImportsHardeningTest extends TestCase
{
    public function testAmountsHaveCeilingAndNeverOverflow(): void
    {
        self::assertSame(-123456, OfxParser::amount('-1234.56'));
        self::assertSame(Invoices::MAX_CENTS, OfxParser::amount('999999999.99'));
        self::assertSame(100, OfxParser::amount('000000000000001.00'), 'Zeros à esquerda não contam.');
        self::assertNull(OfxParser::amount('99999999999999999999.00'));
        self::assertNull(OfxParser::amount('-99999999999999999999'));

        self::assertSame(123456, ImportRules::money('1.234,56'));
        self::assertSame(Invoices::MAX_CENTS, ImportRules::money('999999999.99'));
        foreach (['99999999999999999999', '1.000.000.000,00', '99999999999999999999999999.5'] as $tooBig) {
            self::assertNull(ImportRules::money($tooBig), $tooBig);
        }
    }

    public function testColumnIndexNeverOverflows(): void
    {
        self::assertSame(2, SpreadsheetReader::columnIndex('C12'));
        self::assertSame(16383, SpreadsheetReader::columnIndex('XFD1'));
        self::assertSame(PHP_INT_MAX, SpreadsheetReader::columnIndex(str_repeat('Z', 40) . '1'));
    }

    public function testSparseXlsxIsRefusedInsteadOfFillingMemory(): void
    {
        $farColumn = '<row r="1"><c r="A1" t="inlineStr"><is><t>unidade</t></is></c><c r="XFD1" t="inlineStr"><is><t>x</t></is></c></row>';
        $farRow = '<row r="1"><c r="A1" t="inlineStr"><is><t>unidade</t></is></c></row><row r="1048576"><c r="A1048576"><v>1</v></c></row>';
        foreach (['colunas' => $farColumn, 'linha' => $farRow] as $expected => $rows) {
            try {
                SpreadsheetReader::xlsx(self::xlsx($rows));
                self::fail("Aceitou XLSX esparso ({$expected}).");
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString($expected, $error->getMessage());
            }
        }
        $ok = SpreadsheetReader::xlsx(self::xlsx('<row r="1"><c r="A1" t="inlineStr"><is><t>unidade</t></is></c><c r="B1" t="inlineStr"><is><t>fracao</t></is></c></row>'
            . '<row r="2"><c r="A2" t="inlineStr"><is><t>101</t></is></c><c r="B2"><v>0.5</v></c></row>'));
        self::assertSame([['unidade', 'fracao'], ['101', '0.5']], $ok);
    }

    private static function xlsx(string $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx-test');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rows . '</sheetData></worksheet>');
        $zip->close();
        $content = (string)file_get_contents($path);
        @unlink($path);
        return $content;
    }
}
