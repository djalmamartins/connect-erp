<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ChartReports;

/** Regras puras dos gráficos (ADR 0083): meses, séries completas, top + "Outras" e taxa. */
final class ChartReportsTest extends TestCase
{
    public function testMonthsAndFill(): void
    {
        self::assertSame(['2026-11', '2026-12', '2027-01'], ChartReports::months('2026-11', '2027-01'), 'Virada de ano.');
        self::assertSame([5, 0, 7], ChartReports::fill(['2026-11', '2026-12', '2027-01'], ['2027-01' => 7, '2026-11' => 5]), 'Mês sem dado = zero, na ordem.');
        $this->expectException(InvalidArgumentException::class);
        ChartReports::months('2025-01', '2027-01');
    }

    public function testTopGroupsTheRestInOthers(): void
    {
        $months = ['2026-11', '2026-12'];
        $byKey = ['a' => ['2026-11' => 10], 'b' => ['2026-11' => 50, '2026-12' => 50], 'c' => ['2026-12' => 30], 'd' => ['2026-11' => 1, '2026-12' => 2]];
        $series = ChartReports::top($months, $byKey, ['b' => 'Conta B'], 2);
        self::assertSame(['b', 'c', 'others'], array_column($series, 'key'), 'Maiores primeiro; o resto em Outras.');
        self::assertSame('Conta B', $series[0]['label']);
        self::assertSame([11, 2], $series[2]['data'], 'Outras = a + d por mês.');
        self::assertSame(['b', 'c'], array_column(ChartReports::top($months, ['b' => $byKey['b'], 'c' => $byKey['c']], [], 2), 'key'), 'Sem resto, sem Outras.');
    }

    public function testRate(): void
    {
        self::assertSame(12.5, ChartReports::rate(1250, 10000));
        self::assertSame(0.0, ChartReports::rate(0, 10000));
        self::assertNull(ChartReports::rate(100, 0), 'Sem faturamento, sem taxa.');
    }
}
