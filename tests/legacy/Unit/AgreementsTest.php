<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\Registry\Definitions;

/** Inadimplência e acordos: regras puras do atraso e do parcelamento, e a trava (ADR 0039). */
final class AgreementsTest extends TestCase
{
    public function testDaysLateAndBuckets(): void
    {
        self::assertSame(0, Agreements::daysLate('2026-11-10', '2026-11-10'));
        self::assertSame(0, Agreements::daysLate('2026-11-10', '2026-11-01'), 'A vencer não tem atraso.');
        self::assertSame(31, Agreements::daysLate('2026-10-10', '2026-11-10'));
        self::assertSame(['1-30', '31-60', '61-90', '90+'], [Agreements::bucket(1), Agreements::bucket(31), Agreements::bucket(90), Agreements::bucket(91)]);
    }

    public function testPlanSplitsCentsAndClampsMonthEnd(): void
    {
        $plan = Agreements::plan(100000, 3, '2027-01-31');
        self::assertSame([33334, 33333, 33333], array_column($plan, 'amount_cents'), 'Sobra vai para a primeira.');
        self::assertSame(100000, array_sum(array_column($plan, 'amount_cents')));
        self::assertSame(['2027-01-31', '2027-02-28', '2027-03-31'], array_column($plan, 'due_on'));
        self::assertSame([1, 2, 3], array_column($plan, 'number'));
        self::assertSame(['2027-12-15', '2028-01-15'], array_column(Agreements::plan(200, 2, '2027-12-15'), 'due_on'), 'Vira o ano.');
    }

    public function testPlanRejectsInvalidInput(): void
    {
        foreach ([[0, 1, '2027-01-10'], [1000, 0, '2027-01-10'], [1000, 61, '2027-01-10'], [2, 3, '2027-01-10'], [1000, 2, '2027-02-30'], [1000, 2, '']] as [$total, $n, $due]) {
            try {
                Agreements::plan($total, $n, $due);
                self::fail("Plano aceitou entrada inválida: {$total}/{$n}/{$due}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testAgreementEntriesAreLockedLikeInvoiceEntries(): void
    {
        self::assertSame(['invoice_item_id', 'agreement_id'], Definitions::INVOICE_LOCK['fields']);
        $definition = Definitions::get('receivables');
        self::assertTrue(Definitions::lockedByOrigin($definition, ['agreement_id' => 7, 'invoice_item_id' => null]));
        self::assertTrue(Definitions::lockedByOrigin($definition, ['agreement_id' => null, 'invoice_item_id' => 3]));
        self::assertFalse(Definitions::lockedByOrigin($definition, ['agreement_id' => null, 'invoice_item_id' => null]));
        self::assertFalse(Definitions::lockedByOrigin(Definitions::get('units'), ['agreement_id' => 7]), 'Sem origin_lock, nada trava.');
    }
}
