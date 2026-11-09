<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\ChartScreens as Screens;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas dos gráficos (AP-102 a AP-109; ADR 0083 e 0078): tela própria só com dados, permissões iguais
 * às da API, um eixo por painel e a geometria das barras.
 */
final class ErpChartScreensTest extends TestCase
{
    public function testPagesPermissionsAndTemplate(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (array_keys(Screens::PAGES) as $page) {
            self::assertSame(Screens::permissions($page)[0], $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame('pages/reports/chart', Panel::ownTemplate($page, ['chartReady' => true]));
        }
        self::assertSame(['erp.entries.view', 'erp.chart.view'], Screens::permissions('AP-103'));
        self::assertSame(['erp.entries.view', 'erp.bank_accounts.view'], Screens::permissions('AP-108'));
    }

    public function testFilters(): void
    {
        self::assertSame(['de' => '2025-12', 'ate' => '2026-11', 'natureza' => 'despesa', 'unidade' => null], Screens::filters([], '2026-11'));
        self::assertSame(['de' => '2026-01', 'ate' => '2026-03', 'natureza' => 'receita', 'unidade' => 7],
            Screens::filters(['de' => '2026-01', 'ate' => '2026-03', 'natureza' => 'receita', 'unidade' => '7'], '2026-11'));
        self::assertSame(['de' => '2025-04', 'ate' => '2026-03', 'natureza' => 'despesa', 'unidade' => null],
            Screens::filters(['de' => '2026-13', 'ate' => '2026-03', 'natureza' => 'x', 'unidade' => '-1'], '2026-11'));
        self::assertNull(Screens::month(['2026-01']));
        self::assertSame('nov/26', Screens::monthLabel('2026-11'));
        self::assertSame('−R$ 1.234,56', Screens::format(-123456, 'cents'));
        self::assertSame('12,50%', Screens::format(12.5, 'percent'));
        self::assertSame('—', Screens::format(null, 'percent'));
        self::assertSame('1,500', Screens::format(1.5, 'consumption'));
    }

    public function testPanelsKeepOneAxisAndMeasureNegativeBars(): void
    {
        $chart = ['labels' => ['2026-10', '2026-11'], 'unit' => 'cents', 'series' => [
            ['key' => 'billed', 'label' => 'Cobrado', 'data' => [100, 300]],
            ['key' => 'overdue', 'label' => 'Vencido', 'data' => [-100, 0]],
            ['key' => 'rate', 'label' => 'Inadimplência (%)', 'unit' => 'percent', 'data' => [null, 25.0]],
        ]];
        $panels = Screens::panels($chart, 'delinquency');
        self::assertCount(2, $panels, 'a taxa em % vai para um painel próprio, sem segundo eixo');
        [$money, $rate] = $panels;
        self::assertNull($money['title']);
        self::assertSame('Inadimplência (%)', $rate['title']);
        self::assertSame(['1', '2'], array_column($money['series'], 'color'));
        self::assertSame(25.0, $money['zero']);
        self::assertSame([25.0, 25.0, 100], $money['columns'][0]['bars'][0]);
        self::assertSame([0.0, 25.0, -100], $money['columns'][0]['bars'][1]);
        self::assertSame([25.0, 75.0, 300], $money['columns'][1]['bars'][0]);
        self::assertSame([0.0, 0.0, null], $rate['columns'][0]['bars'][0]);
        self::assertFalse($money['stacked']);

        $consumption = Screens::panels(['labels' => ['2026-11'], 'unit' => 'consumption', 'series' => [
            ['key' => 'agua', 'label' => 'Água', 'data' => [10.5]], ['key' => 'gas', 'label' => 'Gás', 'data' => [2.0]]]], 'consumption');
        self::assertSame(['Água', 'Gás'], array_column($consumption, 'title'), 'consumo: um painel por tipo, cada um na sua escala');
        self::assertSame(100.0, $consumption[1]['columns'][0]['bars'][0][1]);
    }

    public function testStackedCompositionAndEmpty(): void
    {
        $chart = ['labels' => ['2026-10', '2026-11'], 'unit' => 'count', 'series' => [
            ['key' => 'a1', 'label' => 'Salão', 'data' => [1, 3]], ['key' => 'others', 'label' => 'Outras', 'data' => [1, 1]]]];
        [$panel] = Screens::panels($chart, 'common-areas');
        self::assertTrue($panel['stacked']);
        self::assertSame(4, $panel['top']);
        self::assertSame(['1', 'other'], array_column($panel['series'], 'color'));
        self::assertSame([[0.0, 75.0, 3], [75.0, 25.0, 1]], $panel['columns'][1]['bars']);
        self::assertTrue(Screens::panels(['labels' => ['2026-11'], 'unit' => 'cents', 'series' => [['key' => 'x', 'label' => 'X', 'data' => [0]]]], 'cash')[0]['empty']);
        $table = Screens::table($chart + ['series' => []]);
        self::assertSame(['2026-10', '2026-11'], $table['months']);
        $table = Screens::table(['labels' => ['2026-11'], 'unit' => 'cents', 'series' => [['key' => 'r', 'label' => 'Taxa', 'unit' => 'percent', 'data' => [null]], ['key' => 'b', 'label' => 'B', 'data' => [5]]]]);
        self::assertNull($table['series'][0]['total']);
        self::assertSame(5, $table['series'][1]['total']);
    }
}
