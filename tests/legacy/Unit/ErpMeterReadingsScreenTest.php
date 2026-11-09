<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Tela de leituras do mês (AP-042, ADR 0045 e 0048): folha por relógio (Panel::readingSheet),
 * lançamento em lote e faturamento pela API /api/v1/meter-readings.
 */
final class ErpMeterReadingsScreenTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOwnTemplateOnlyWithRealData(): void
    {
        self::assertNull(Panel::ownTemplate('AP-042', ['meterSheet' => null]));
        self::assertSame('pages/operational/meter-readings', Panel::ownTemplate('AP-042', ['meterSheet' => []]));
    }

    public function testSheetUsesTheLatestEarlierReadingOrTheInitialOne(): void
    {
        $meters = [
            ['id' => 1, 'condominium_id' => 1, 'condominium_name' => 'B', 'unit_id' => 10, 'number' => 'X', 'initial_reading' => 5.0],
            ['id' => 2, 'condominium_id' => 1, 'condominium_name' => 'A', 'unit_id' => 11, 'number' => 'Y', 'initial_reading' => 7.5],
            ['id' => 3, 'condominium_id' => 1, 'condominium_name' => 'A', 'unit_id' => 12, 'number' => 'Z', 'initial_reading' => null],
        ];
        $readings = [
            ['meter_id' => 1, 'competence' => '2026-12', 'reading' => 99.0, 'previous_reading' => 40.0],
            ['meter_id' => 1, 'competence' => '2026-09', 'reading' => 30.0, 'previous_reading' => 5.0],
            ['meter_id' => 1, 'competence' => '2026-10', 'reading' => 40.0, 'previous_reading' => 30.0],
            ['meter_id' => 3, 'competence' => '2026-11', 'reading' => 12.0, 'previous_reading' => 2.0],
        ];
        $sheet = Panel::readingSheet($meters, [10 => 'Apto 1', 11 => 'Apto 2'], $readings, '2026-11');
        $by = array_column($sheet, null, 'meter_id');
        self::assertSame([2, 3, 1], array_column($sheet, 'meter_id'), 'por condomínio e unidade');
        self::assertSame(40.0, $by[1]['previous'], 'a mais recente antes da competência, não a posterior');
        self::assertNull($by[1]['reading']);
        self::assertSame(7.5, $by[2]['previous'], 'sem leitura anterior, a inicial do relógio');
        self::assertSame(2.0, $by[3]['previous'], 'com leitura no mês, a anterior gravada nela');
        self::assertSame('—', $by[3]['unit_label']);
    }

    public function testWritesOnlyForManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/operational/meter-readings.php');
        self::assertStringContainsString('$canManageReadings && !$locked', $view);
        self::assertStringContainsString('<?php if ($canManageReadings && $billable > 0 && $context !== null): ?>', $view);
        self::assertStringContainsString('csrf_input()', $view);
        self::assertStringContainsString("!Access::can('erp.meter_readings.view', \$user)", $this->file('source/Controllers/Erp/Connect/Panel.php'));
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-meter-readings]', "'/bill'", 'meter_id', 'read_on', 'chart_account_id', 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
