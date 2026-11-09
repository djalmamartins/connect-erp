<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\BankBalances;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\UnitDocuments;

/** Regras puras dos lançamentos avulsos e do recibo (ADR 0057). */
final class EntryShortcutsTest extends TestCase
{
    public function testSplitMethods(): void
    {
        $fractions = [10 => '0.25', 11 => '0.25', 12 => '0.5'];
        self::assertSame([10 => 12000, 11 => 12000, 12 => 12000], EntryShortcuts::split('fixed', 12000, $fractions), 'fixed: valor por unidade.');
        self::assertSame([10 => 3334, 11 => 3333, 12 => 3333], EntryShortcuts::split('equal', 10000, $fractions), 'equal: centavo que sobra vai para a primeira.');
        self::assertSame([10 => 2500, 11 => 2500, 12 => 5000], EntryShortcuts::split('fraction', 10000, $fractions));
        self::assertSame(10000, array_sum(EntryShortcuts::split('equal', 10000, [1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1, 7 => 1])), 'Rateio nunca perde centavo.');
        foreach ([['equal', 2, $fractions], ['fixed', 100, []], ['other', 100, $fractions], ['fixed', 0, $fractions]] as [$method, $amount, $units]) {
            try {
                EntryShortcuts::split($method, $amount, $units);
                self::fail("Aceitou {$method} {$amount}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDiscountAndReasonRules(): void
    {
        EntryShortcuts::assertDiscount(4999, 5000);
        self::addToAssertionCount(1);
        foreach ([[5000, 5000], [6000, 5000], [0, 5000]] as [$discount, $amount]) {
            try {
                EntryShortcuts::assertDiscount($discount, $amount);
                self::fail('Desconto inválido aceito.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame('Motivo com espaços', EntryShortcuts::reason("  Motivo   com\nespaços ", 'do teste'));
        $this->expectException(InvalidArgumentException::class);
        EntryShortcuts::reason('abc', 'do teste');
    }

    public function testTransfersEnterBalanceAndChargesAreLocked(): void
    {
        self::assertSame(1000 + 500 - 200 + 300 - 100, BankBalances::balance(1000, 500, 200, 300, 100));
        self::assertSame(1300, BankBalances::balance(1000, 500, 200), 'Sem transferência, a regra antiga continua.');
        self::assertContains('extra_charge_id', Definitions::INVOICE_LOCK['fields'], 'Conta de cobrança extra ou multa trava no cadastro.');
        self::assertStringContainsString("t.status = 'active'", BankBalances::transferSql('in'), 'Transferência cancelada sai do saldo.');
    }

    public function testReceiptLocksPaymentOnEntryRegistries(): void
    {
        foreach (['entries', 'receivables', 'payables'] as $slug) {
            self::assertSame(Definitions::RECEIPT_LOCK, Definitions::get($slug)['receipt_lock'] ?? null, "{$slug} trava a baixa de conta com recibo.");
        }
        self::assertContains('paid_on', Definitions::RECEIPT_LOCK['columns'], 'Estornar a baixa exige cancelar o recibo.');
        self::assertStringContainsString('Cancele o recibo', Definitions::RECEIPT_LOCK['message']);
    }

    public function testReceiptKindKeepsCatalogDocumentsApart(): void
    {
        self::assertArrayNotHasKey('receipt', UnitDocuments::KINDS, 'As páginas AP-123 a 130 continuam com os 4 documentos.');
        self::assertSame('Recibo', UnitDocuments::ALL_KINDS['receipt']);
        self::assertSame(array_keys(UnitDocuments::KINDS), array_slice(array_keys(UnitDocuments::ALL_KINDS), 0, 4));
    }
}
