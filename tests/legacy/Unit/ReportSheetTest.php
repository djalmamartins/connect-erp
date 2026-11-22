<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\Erp\ReportSheet;

/** ADR 0635: base única de relatório (?imprimir=1). */
final class ReportSheetTest extends TestCase
{
    public function testActiveOnlyWithImprimirOne(): void
    {
        self::assertTrue(ReportSheet::active(['imprimir' => '1']));
        self::assertFalse(ReportSheet::active([]));
        self::assertFalse(ReportSheet::active(['imprimir' => '0']));
        self::assertFalse(ReportSheet::active(['imprimir' => ['1']]));
    }

    public function testUrlKeepsFilters(): void
    {
        self::assertSame('/relatorios/consumo?de=2026-09&ate=2026-11&unidade=5&imprimir=1',
            ReportSheet::url('/relatorios/consumo', ['de' => '2026-09', 'ate' => '2026-11', 'unidade' => '5']));
        self::assertSame('/x?imprimir=1', ReportSheet::url('/x', ['imprimir' => '0']));
    }

    public function testIssuerAndIssuedAt(): void
    {
        self::assertSame('Ana Exemplo', ReportSheet::issuer((object)['first_name' => 'Ana', 'last_name' => 'Exemplo']));
        self::assertSame('Ana', ReportSheet::issuer(['first_name' => 'Ana']));
        self::assertSame('', ReportSheet::issuer(null));
        self::assertMatchesRegularExpression('~^\d{2}/\d{2}/\d{4} às \d{2}:\d{2}$~', ReportSheet::issuedAt(mktime(8, 30, 0, 11, 22, 2026)));
    }
}
