<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ManagementReports;

/** Regras puras dos relatórios gerenciais (ADR 0082). */
final class ManagementReportsTest extends TestCase
{
    public function testCompetenceRange(): void
    {
        self::assertSame(['2025-01', '2026-12'], ManagementReports::competences('2025-01', '2026-12'), '24 competências cabem.');
        foreach ([['2026-02', '2026-01'], ['2025-01', '2027-01'], ['2026-13', '2026-12'], ['01/2026', '2026-02']] as [$from, $to]) {
            try {
                ManagementReports::competences($from, $to);
                self::fail("Aceitou {$from} a {$to}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testSituationVariationAndRunningBalance(): void
    {
        self::assertSame('cancelled', ManagementReports::situation(['status' => 'inactive', 'paid_on' => '2026-11-01', 'due_on' => '2026-11-01'], '2026-11-09'));
        self::assertSame('paid', ManagementReports::situation(['status' => 'active', 'paid_on' => '2026-11-01', 'due_on' => '2026-10-01'], '2026-11-09'));
        self::assertSame('overdue', ManagementReports::situation(['status' => 'active', 'paid_on' => null, 'due_on' => '2026-11-08'], '2026-11-09'));
        self::assertSame('open', ManagementReports::situation(['status' => 'active', 'paid_on' => null, 'due_on' => '2026-11-09'], '2026-11-09'), 'Vence hoje: a vencer.');
        self::assertSame(['difference_cents' => 5000, 'percent' => 50.0], ManagementReports::variation(10000, 15000));
        self::assertSame(['difference_cents' => 100, 'percent' => null], ManagementReports::variation(0, 100), 'Base zero: sem percentual.');
        self::assertSame([100, -50, 250], array_column(ManagementReports::runningBalance(0, [['amount_cents' => 100], ['amount_cents' => -150], ['amount_cents' => 300]]), 'balance_cents'));
    }

    public function testSharesNeverLoseCents(): void
    {
        $shares = ManagementReports::shares([['id' => 1, 'amount_cents' => 100001], ['id' => 2, 'amount_cents' => 0]], [10 => '0.333333', 11 => '0.333333', 12 => '0.333334']);
        self::assertSame(100001, array_sum($shares[1]), 'O rateio soma a despesa inteira.');
        self::assertSame([10 => 0, 11 => 0, 12 => 0], $shares[2]);
    }

    /** ADR 0068: o rateio recusa a matriz grande demais, e o CSV gerencial usa o csvCell estrito do ADR 0067. */
    public function testApportionmentMatrixCapAndStrictCsv(): void
    {
        ManagementReports::assertMatrix(ManagementReports::MAX_ROWS, 100);
        foreach ([[ManagementReports::MAX_ROWS + 1, 1], [1000, 501]] as [$expenses, $units]) {
            try {
                ManagementReports::assertMatrix($expenses, $units);
                self::fail("Aceitou {$expenses} × {$units}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $table = ManagementReports::csvTable('account-statement', ['rows' => [
            ['on_date' => '2026-11-05', 'description' => ' =IMPORTXML("x")', 'amount_cents' => 100, 'balance_cents' => 100],
            ['on_date' => '2026-11-05', 'description' => '＠SUM(A1)', 'amount_cents' => 100, 'balance_cents' => 200],
        ]]);
        self::assertSame(["' =IMPORTXML(\"x\")", "'＠SUM(A1)"], array_column($table['rows'], 1));
    }

    public function testCsvTableIsSanitized(): void
    {
        $table = ManagementReports::csvTable('account-statement', ['rows' => [
            ['on_date' => '2026-11-05', 'description' => '=IMPORTXML("x")', 'amount_cents' => -1290, 'balance_cents' => 48710],
        ]]);
        self::assertSame(['Data', 'Histórico', 'Valor', 'Saldo'], $table['header']);
        self::assertSame(['05/11/2026', "'=IMPORTXML(\"x\")", "'-12,90", '487,10'], $table['rows'][0], 'Fórmula e valor negativo ganham apóstrofo (ADR 0081).');
        foreach (['consumption', 'cost-centers', 'comparison', 'statement-accounts', 'statement-analytic', 'statement-situation', 'apportionment-unit', 'apportionment-compare',
            'apportionment-unit_account', 'apportionment-unit_entries', 'apportionment-entries', 'apportionment-account', 'entries', 'entries-detailed', 'bank-charges'] as $kind) {
            self::assertNotEmpty(ManagementReports::csvTable($kind, ['rows' => []])['header'], $kind);
        }
        $this->expectException(InvalidArgumentException::class);
        ManagementReports::csvTable('outro', []);
    }
}
