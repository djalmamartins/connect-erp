<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Collections;

/** Regras puras da cobrança e da cobrança judicial (ADR 0080). */
final class CollectionsTest extends TestCase
{
    public function testCaseTransitionsEndAtClosed(): void
    {
        self::assertTrue(Collections::canTransition('preparing', 'filed'));
        self::assertTrue(Collections::canTransition('agreement', 'filed'), 'Acordo judicial quebrado volta a ajuizado.');
        self::assertFalse(Collections::canTransition('filed', 'preparing'));
        self::assertFalse(Collections::canTransition('closed', 'filed'), 'Encerrado é final.');
        self::assertSame([], Collections::TRANSITIONS['closed']);
        self::assertSame(array_keys(Collections::CASE_STATUSES), array_keys(Collections::TRANSITIONS));
    }

    public function testPromiseBroken(): void
    {
        $promise = ['outcome' => 'promise', 'promise_on' => '2026-11-05'];
        self::assertTrue(Collections::promiseBroken($promise, '2026-11-09', true));
        self::assertFalse(Collections::promiseBroken($promise, '2026-11-05', true), 'No dia prometido ainda vale.');
        self::assertFalse(Collections::promiseBroken($promise, '2026-11-09', false), 'Pagou: não é quebra.');
        self::assertFalse(Collections::promiseBroken(['outcome' => 'no_answer', 'promise_on' => null], '2026-11-09', true));
        self::assertFalse(Collections::promiseBroken(null, '2026-11-09', true));
    }

    /** ADR 0066: contas do caso conferidas antes de converter, sem repetição e com teto. */
    public function testEntryIdsAreValidatedAndCapped(): void
    {
        self::assertSame([5, 9], Collections::entryIds([5, '9', '5']));
        foreach ([null, 'x', [], ['1a'], [0], [-3], [1.5], array_fill(0, Collections::MAX_CASE_ENTRIES + 1, 1)] as $bad) {
            try {
                Collections::entryIds($bad);
                self::fail('Aceitou ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testFutureDateWindow(): void
    {
        self::assertNull(Collections::futureDate('', 90, 'x', '2026-11-09'));
        self::assertSame('2026-11-09', Collections::futureDate('2026-11-09', 90, 'x', '2026-11-09'));
        self::assertSame('2027-02-07', Collections::futureDate('2027-02-07', 90, 'x', '2026-11-09'));
        foreach (['2026-11-08', '2027-02-08', '09/11/2026'] as $bad) {
            try {
                Collections::futureDate($bad, 90, 'x', '2026-11-09');
                self::fail("Aceitou {$bad}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
