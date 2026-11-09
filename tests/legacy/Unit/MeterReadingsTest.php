<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\MeterReadings;
use Source\Services\Erp\Registry\Definitions;

/** Leituras: valor por faixas progressivas, leitura decimal e as definições (ADR 0045). */
final class MeterReadingsTest extends TestCase
{
    public function testProgressiveBands(): void
    {
        $bands = [
            ['from_amount' => '10', 'to_amount' => '20', 'price_cents' => 800],
            ['from_amount' => '0', 'to_amount' => '10', 'price_cents' => 500],
            ['from_amount' => '20', 'to_amount' => null, 'price_cents' => 1200],
        ];
        self::assertSame(2500, MeterReadings::charge(5, $bands), '5 m³ na primeira faixa.');
        self::assertSame(5000 + 8000, MeterReadings::charge(20, $bands));
        self::assertSame(5000 + 8000 + 3600, MeterReadings::charge(23, $bands), 'Sem limite na última faixa.');
        self::assertSame(1250, MeterReadings::charge(2.5, $bands), 'Fração de m³.');
        self::assertSame(0, MeterReadings::charge(0, $bands));
        self::assertSame(0, MeterReadings::charge(12, []), 'Sem tarifa, valor zero.');
    }

    public function testReadingParsing(): void
    {
        self::assertSame(123.456, MeterReadings::parseReading('123,456'));
        self::assertSame(10.0, MeterReadings::parseReading('10'));
        self::assertSame(7.5, MeterReadings::parseReading(7.5));
        foreach (['-1', '1,2345', 'abc', '', null, ['1'], -2] as $bad) {
            self::assertNull(MeterReadings::parseReading($bad), var_export($bad, true));
        }
    }

    public function testDefinitions(): void
    {
        $meters = Definitions::get('meters');
        self::assertSame(['required', 'operacional', 'AP-041'], [$meters['scope'], $meters['group'], $meters['page']]);
        self::assertSame(['agua', 'gas', 'energia'], array_keys($meters['fields']['kind']['options']));
        $tariffs = Definitions::get('tariffs');
        self::assertSame(['optional', 'AP-043'], [$tariffs['scope'], $tariffs['page']]);
        self::assertSame('from_amount', $tariffs['fields']['to_amount']['not_below']);
        self::assertContains('erp_meters', Definitions::get('units')['referenced_by']);
    }
}
