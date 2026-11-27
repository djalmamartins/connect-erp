<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\PartnerAccess;

/** ADR 0684: a imobiliária vê se a unidade está em dia, sem ver as contas. */
final class PartnerPaymentSummaryTest extends TestCase
{
    public function testOverdueOpenAndLastPayment(): void
    {
        $s = PartnerAccess::paymentSummary([
            ['due_on' => '2026-09-10', 'paid_on' => '2026-09-08', 'amount_cents' => 50000],
            ['due_on' => '2026-10-10', 'paid_on' => null, 'amount_cents' => 50000],
            ['due_on' => '2026-08-10', 'paid_on' => null, 'amount_cents' => 30000],
            ['due_on' => '2026-12-10', 'paid_on' => null, 'amount_cents' => 50000],
        ], '2026-11-20');
        self::assertSame(['situation' => 'em_atraso', 'overdue_count' => 2, 'overdue_cents' => 80000, 'oldest_overdue_on' => '2026-08-10',
            'open_cents' => 130000, 'last_paid_on' => '2026-09-08'], $s);
    }

    public function testDueTodayIsNotLateAndNothingIsUpToDate(): void
    {
        self::assertSame('em_dia', PartnerAccess::paymentSummary([['due_on' => '2026-11-20', 'paid_on' => null, 'amount_cents' => 100]], '2026-11-20')['situation']);
        self::assertSame(['situation' => 'em_dia', 'overdue_count' => 0, 'overdue_cents' => 0, 'oldest_overdue_on' => null, 'open_cents' => 0, 'last_paid_on' => null],
            PartnerAccess::paymentSummary([], '2026-11-20'));
    }
}
