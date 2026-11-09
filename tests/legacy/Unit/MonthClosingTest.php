<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryLocked;

/** Fechamento mensal: regra pura do resumo e trava declarada nas definições (ADR 0031). */
final class MonthClosingTest extends TestCase
{
    public function testSummarizeTotalsOpenPartsAccountsAndBanks(): void
    {
        $summary = MonthClosing::summarize([
            ['nature' => 'receita', 'amount_cents' => '100000', 'paid_on' => '2026-11-05', 'account_code' => '1.01', 'account_name' => 'Taxa', 'bank_account_id' => 7, 'bank_name' => 'Movimento'],
            ['nature' => 'receita', 'amount_cents' => 30000, 'paid_on' => null, 'account_code' => '1.01', 'account_name' => 'Taxa', 'bank_account_id' => null, 'bank_name' => null],
            ['nature' => 'despesa', 'amount_cents' => 45000, 'paid_on' => '2026-11-10', 'account_code' => '2.01', 'account_name' => 'Água', 'bank_account_id' => 7, 'bank_name' => 'Movimento'],
            ['nature' => 'despesa', 'amount_cents' => 5000, 'paid_on' => null, 'account_code' => '2.01', 'account_name' => 'Água', 'bank_account_id' => 7, 'bank_name' => 'Movimento'],
        ]);

        self::assertSame(130000, $summary['revenue_cents']);
        self::assertSame(50000, $summary['expense_cents']);
        self::assertSame(30000, $summary['open_revenue_cents']);
        self::assertSame(5000, $summary['open_expense_cents']);
        self::assertSame(80000, $summary['result_cents']);
        self::assertSame(4, $summary['entries']);
        self::assertSame([['code' => '1.01', 'name' => 'Taxa', 'nature' => 'receita', 'total_cents' => 130000], ['code' => '2.01', 'name' => 'Água', 'nature' => 'despesa', 'total_cents' => 50000]], $summary['by_account']);
        self::assertSame([['id' => 7, 'name' => 'Movimento', 'received_cents' => 100000, 'paid_cents' => 45000]], $summary['by_bank_account'], 'Conta bancária só soma o que foi pago.');
    }

    public function testEmptyMonthAndNegativeResult(): void
    {
        $empty = MonthClosing::summarize([]);
        self::assertSame(0, $empty['result_cents']);
        self::assertSame([], $empty['by_account']);
        self::assertSame(-2000, MonthClosing::summarize([['nature' => 'despesa', 'amount_cents' => 2000, 'paid_on' => null, 'account_code' => '2', 'account_name' => 'X', 'bank_account_id' => null, 'bank_name' => null]])['result_cents']);
    }

    public function testEntryDefinitionsAreLockedByClosing(): void
    {
        foreach (['entries', 'payables', 'receivables'] as $slug) {
            self::assertSame('competence', Definitions::get($slug)['closing_lock'] ?? null, $slug);
        }
        self::assertArrayNotHasKey('closing_lock', Definitions::get('recurring_entries'), 'O modelo não tem competência; quem trava é o lançamento gerado.');
        self::assertStringContainsString('2026-11', (new RegistryLocked('2026-11'))->getMessage());
    }
}
