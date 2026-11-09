<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\Definitions;

/** Faturas: regras puras do rateio e dos valores, e a trava declarada nos lançamentos (ADR 0033). */
final class InvoicesTest extends TestCase
{
    public function testApportionKeepsTotalAndGivesCentsToLargestRemainders(): void
    {
        self::assertSame([1 => 334, 2 => 333, 3 => 333], Invoices::apportion(1000, [1 => 1, 2 => 1, 3 => 1]), 'Empate: o centavo vai para a primeira.');
        $shares = Invoices::apportion(100000, [10 => '0.012500', 11 => '0.025000', 12 => '0.962500']);
        self::assertSame([10 => 1250, 11 => 2500, 12 => 96250], $shares);
        $odd = Invoices::apportion(999, ['a' => 0.3, 'b' => 0.3, 'c' => 0.4]);
        self::assertSame(999, array_sum($odd));
        // 299,7 + 299,7 + 399,6: os 2 centavos que sobram vão para as maiores sobras (a e b).
        self::assertSame(['a' => 300, 'b' => 300, 'c' => 399], $odd);
    }

    public function testApportionRejectsMissingFractionsAndZeroTotal(): void
    {
        foreach ([[1000, [1 => 0.5, 2 => null]], [1000, [1 => 0]], [1000, []], [0, [1 => 1]]] as [$total, $fractions]) {
            try {
                Invoices::apportion($total, $fractions);
                self::fail('Rateio aceitou entrada inválida.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testCentsParsing(): void
    {
        self::assertSame(123456, Invoices::cents('1.234,56'));
        self::assertSame(50000, Invoices::cents('500'));
        self::assertSame(50050, Invoices::cents('500,5'));
        self::assertSame(1999, Invoices::cents(1999));
        foreach (['0', '0,00', '-10', '10.5', 'abc', '', null, 0, -5, 1.5, ['1']] as $invalid) {
            self::assertNull(Invoices::cents($invalid), var_export($invalid, true));
        }
    }

    public function testEntriesFromInvoicesAreLockedAndHaveUnit(): void
    {
        foreach (['entries', 'payables', 'receivables'] as $slug) {
            $definition = Definitions::get($slug);
            self::assertSame(Definitions::INVOICE_LOCK, $definition['origin_lock'] ?? null, $slug);
            self::assertSame('unit', $definition['fields']['unit_id']['type'] ?? null, $slug);
        }
        self::assertArrayNotHasKey('unit_id', Definitions::get('recurring_entries')['fields'], 'Lançamento fixo não tem unidade.');
        self::assertNotContains('paid_on', Definitions::INVOICE_LOCK['columns'], 'A baixa continua livre.');
        self::assertContains('erp_entries', Definitions::get('units')['referenced_by'], 'Unidade com lançamento não sai.');
    }

    public function testCentsHasCeilingAndNeverOverflows(): void
    {
        self::assertSame(Invoices::MAX_CENTS, Invoices::cents('999.999.999,99'));
        self::assertSame(Invoices::MAX_CENTS, Invoices::cents(Invoices::MAX_CENTS));
        foreach (['1.000.000.000,00', '99999999999999999999', '99999999999999999999,99', Invoices::MAX_CENTS + 1, PHP_INT_MAX] as $tooBig) {
            self::assertNull(Invoices::cents($tooBig), var_export($tooBig, true));
        }
        self::assertSame(100, Invoices::cents('0000000000001,00'), 'Zeros à esquerda não contam para o teto.');
    }
}
