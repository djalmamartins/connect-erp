<?php

declare(strict_types=1);

namespace Tests\Legacy\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Resident\ResidentPortal;

final class ResidentCalendarTest extends TestCase
{
    private const AREA = ['booking_mode' => 'approval', 'min_advance_days' => 2, 'max_advance_days' => 20, 'opening_time' => '10:00', 'closing_time' => '22:00'];

    public function testDaysBeforeTodayAndOutsideTheWindowCannotBePicked(): void
    {
        $days = ResidentPortal::calendar(self::AREA, [], '2026-11', '2026-11-14');
        self::assertCount(30, $days);
        self::assertSame('past', $days['2026-11-13']);
        self::assertSame('out', $days['2026-11-14']);
        self::assertSame('out', $days['2026-11-15']);
        self::assertSame('free', $days['2026-11-16']);
        self::assertSame('free', $days['2026-11-30']);
        self::assertSame('out', ResidentPortal::calendar(self::AREA, [], '2026-12', '2026-11-14')['2026-12-05']);
    }

    public function testTakenHoursMakeTheDayPartialOrBusy(): void
    {
        $busy = [
            ['date' => '2026-11-20', 'starts_time' => '18:00', 'ends_time' => '22:00'],
            ['date' => '2026-11-21', 'starts_time' => '10:00', 'ends_time' => '15:00'],
            ['date' => '2026-11-21', 'starts_time' => '15:00', 'ends_time' => '22:00'],
            ['date' => '2026-11-22', 'starts_time' => null, 'ends_time' => null],
        ];
        $days = ResidentPortal::calendar(self::AREA, $busy, '2026-11', '2026-11-14');
        self::assertSame('partial', $days['2026-11-20']);
        self::assertSame('busy', $days['2026-11-21']);
        self::assertSame('busy', $days['2026-11-22'], 'reserva sem horário toma o dia todo');
    }

    public function testBlockedAreaHasNoPickableDay(): void
    {
        $days = ResidentPortal::calendar(['booking_mode' => 'blocked'] + self::AREA, [], '2026-11', '2026-11-14');
        self::assertNotContains('free', $days);
        self::assertSame('blocked', $days['2026-11-20']);
    }

    public function testPreviousWindowCoversTheSixMonthsBefore(): void
    {
        self::assertSame(['2025-05-01', '2025-10-31'], ResidentPortal::previousWindow('2025-11-01'));
        self::assertSame(['2024-11-01', '2025-04-30'], ResidentPortal::previousWindow('2025-05-01'));
    }
}
