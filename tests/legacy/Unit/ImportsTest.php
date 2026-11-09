<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Imports\ImportRules;
use Source\Services\Erp\Imports\Imports;
use Source\Services\Erp\Imports\OfxParser;
use Source\Services\Erp\Imports\SpreadsheetReader;
use ZipArchive;

/** Regras puras das importações: planilha, OFX, valores e conciliação (ADR 0058). */
final class ImportsTest extends TestCase
{
    public function testCsvDetectsDelimiterEncodingAndHeader(): void
    {
        $sheet = SpreadsheetReader::read("\xEF\xBB\xBFUnidade;Fração\n101;0,25\n\n102;\"0,75\"\n", 'fracoes.csv');
        self::assertSame(['unidade', 'fracao'], $sheet['header']);
        self::assertSame([['line' => 2, 'values' => ['unidade' => '101', 'fracao' => '0,25']], ['line' => 4, 'values' => ['unidade' => '102', 'fracao' => '0,75']]], $sheet['rows'], 'Linha vazia é ignorada e a numeração segue o arquivo.');
        $latin = SpreadsheetReader::read(mb_convert_encoding("Descrição,Valor\nÁgua,10\n", 'ISO-8859-1', 'UTF-8'), 'x.csv');
        self::assertSame(['descricao', 'valor'], $latin['header']);
        self::assertSame('Água', $latin['rows'][0]['values']['descricao']);
        foreach (["a;a\n1;2\n", "unidade\n", ''] as $bad) {
            try {
                SpreadsheetReader::read($bad, 'x.csv');
                self::fail('Aceitou planilha inválida.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        SpreadsheetReader::read("a\n1\n", 'x.pdf');
    }

    public function testXlsxSharedStringsAndNumbers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xt');
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Unidade</t></si><si><t>Valor</t></si><si><t>101</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c></row>'
            . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1234.5</v></c></row>'
            . '<row r="3"><c r="B3"><v>99</v></c></row></sheetData></worksheet>');
        $zip->close();
        $sheet = SpreadsheetReader::read((string)file_get_contents($path), 'planilha.xlsx');
        unlink($path);
        self::assertSame(['unidade', 'valor'], $sheet['header']);
        self::assertSame(['unidade' => '101', 'valor' => '1234.5'], $sheet['rows'][0]['values']);
        self::assertSame(['unidade' => '', 'valor' => '99'], $sheet['rows'][1]['values'], 'Célula ausente vira vazia (coluna pela referência).');
        self::assertSame([0, 2, 26, 27], array_map([SpreadsheetReader::class, 'columnIndex'], ['A1', 'C9', 'AA3', 'AB10']));
    }

    public function testOfxV1Parse(): void
    {
        $ofx = "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><BANKACCTFROM><BANKID>001<ACCTID>0056789<ACCTTYPE>CHECKING</BANKACCTFROM>"
            . "<BANKTRANLIST><DTSTART>20261101<DTEND>20261130"
            . "<STMTTRN><TRNTYPE>CREDIT<DTPOSTED>20261105120000[-3:BRT]<TRNAMT>500.00<FITID>A1<MEMO>TED RECEBIDA</STMTTRN>"
            . "<STMTTRN><TRNTYPE>DEBIT<DTPOSTED>20261106<TRNAMT>-12,90<FITID>A2<NAME>TARIFA</STMTTRN>"
            . "</BANKTRANLIST><LEDGERBAL><BALAMT>487.10<DTASOF>20261130</LEDGERBAL></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
        $parsed = OfxParser::parse($ofx);
        self::assertSame(['001', '0056789', '2026-11-01', '2026-11-30', 48710], [$parsed['bank_id'], $parsed['account_id'], $parsed['from'], $parsed['to'], $parsed['ledger_balance_cents']]);
        self::assertSame([['fitid' => 'A1', 'kind' => 'credit', 'posted_on' => '2026-11-05', 'amount_cents' => 50000, 'memo' => 'TED RECEBIDA'],
            ['fitid' => 'A2', 'kind' => 'debit', 'posted_on' => '2026-11-06', 'amount_cents' => -1290, 'memo' => 'TARIFA']], $parsed['transactions']);
        self::assertTrue(OfxParser::sameAccount('0056789', '5678-9'));
        self::assertFalse(OfxParser::sameAccount('0056780', '5678-9'));
        foreach (['sem ofx', str_replace('<FITID>A2', '<FITID>A1', $ofx), str_replace('<ACCTID>0056789', '<ACCTID>', $ofx)] as $bad) {
            try {
                OfxParser::parse($bad);
                self::fail('Aceitou OFX inválido.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCellValues(): void
    {
        self::assertSame([123456, 123456, 123450, 1000, null, null, null], array_map([ImportRules::class, 'money'], ['1.234,56', '1234,56', '1234.5', 'R$ 10', '-5', '0', 'abc']));
        self::assertSame(['2026-11-05', '2026-11-05', '2026-11-05', null, null], array_map([ImportRules::class, 'date'], ['05/11/2026', '2026-11-05', '46331', '31/02/2026', '5/11/26']));
        self::assertSame(['2026-11', '2026-11', '2026-11', null], array_map([ImportRules::class, 'competence'], ['11/2026', '2026-11', '05/11/2026', '13/2026']));
        self::assertSame(['0.012500', '0.012500', '1.000000', null, null], array_map([ImportRules::class, 'fraction'], ['0,0125', '1,25%', '1', '0', '1,5']));
        self::assertSame(['receita', 'despesa', null], array_map([ImportRules::class, 'nature'], ['Receita', 'D', 'outro']));
    }

    public function testMeterKindAndBalanceCheckOnlyReports(): void
    {
        self::assertSame(['agua', 'gas', 'energia', 'energia', null], array_map([ImportRules::class, 'meterKind'], ['Água', 'GÁS', 'energia', 'Luz', 'vapor']));
        self::assertSame(['bank_cents' => 48710, 'system_cents' => 50000, 'difference_cents' => -1290, 'matches' => false], ImportRules::balanceCheck(48710, 50000),
            'Diferença = banco − calculado; nada é ajustado.');
        self::assertTrue(ImportRules::balanceCheck(100, 100)['matches']);
        self::assertSame(['bank_cents' => null, 'system_cents' => null, 'difference_cents' => null, 'matches' => null], ImportRules::balanceCheck(null, null), 'Sem saldo no extrato, sem conferência.');
    }

    public function testMatchNeverGuessesBetweenCandidates(): void
    {
        $lines = [['fitid' => 'A', 'posted_on' => '2026-11-05', 'amount_cents' => 5000], ['fitid' => 'B', 'posted_on' => '2026-11-05', 'amount_cents' => -1290],
            ['fitid' => 'C', 'posted_on' => '2026-11-20', 'amount_cents' => 7000], ['fitid' => 'D', 'posted_on' => '2026-11-05', 'amount_cents' => 5000]];
        $candidates = [['key' => 'e1', 'direction' => 'in', 'on' => '2026-11-03', 'amount_cents' => 5000],
            ['key' => 't9', 'direction' => 'out', 'on' => '2026-11-05', 'amount_cents' => 1290],
            ['key' => 'e2', 'direction' => 'in', 'on' => '2026-11-10', 'amount_cents' => 7000],
            ['key' => 'e3', 'direction' => 'in', 'on' => '2026-11-21', 'amount_cents' => 7000], ['key' => 'e4', 'direction' => 'in', 'on' => '2026-11-19', 'amount_cents' => 7000]];
        self::assertSame(['A' => 'e1', 'B' => 't9', 'C' => null, 'D' => null], ImportRules::match($lines, $candidates),
            'A e B batem; C tem dois candidatos (fica pendente); D não reaproveita o e1.');
    }

    public function testKindsDeclarePageFormatsAndTargetPermission(): void
    {
        self::assertSame(['AP-140', 'AP-144', 'AP-145', 'AP-146', 'AP-143', 'AP-142', 'AP-044'], array_column(Imports::KINDS, 'page'));
        foreach (Imports::KINDS as $kind => $definition) {
            self::assertContains($definition['permission'], ['erp.entries.manage', 'erp.units.manage', 'erp.meter_readings.manage'], $kind);
        }
        self::assertSame('erp.meter_readings.manage', Imports::KINDS['readings']['permission']);
        self::assertSame(['ofx'], Imports::KINDS['ofx']['formats']);
    }
}
