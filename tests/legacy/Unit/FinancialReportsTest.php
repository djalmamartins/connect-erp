<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\FinancialReports;

/** Relatórios financeiros: regras puras do balancete e da conciliação (ADR 0036). */
final class FinancialReportsTest extends TestCase
{
    public function testRollupSumsAnalyticIntoSyntheticByCode(): void
    {
        $row = static fn (string $code, string $name, string $nature, int $planned, int $realized): array => [
            'code' => $code, 'name' => $name, 'nature' => $nature, 'planned_cents' => $planned, 'realized_cents' => $realized, 'open_cents' => $planned - $realized,
        ];
        $tree = FinancialReports::rollup([
            $row('2.01', 'Água', 'despesa', 300, 300),
            $row('1.01.02', 'Fundo de reserva', 'receita', 200, 0),
            $row('1.01.01', 'Taxa', 'receita', 1000, 800),
            $row('1.10', 'Multas', 'receita', 50, 50),
        ], ['1' => 'Receitas', '1.01' => 'Ordinárias', '2' => 'Despesas']);

        self::assertSame(['1', '1.01', '1.01.01', '1.01.02', '1.10', '2', '2.01'], array_column($tree, 'code'), 'Receitas primeiro; 1.10 depois de 1.01.');
        $byCode = array_column($tree, null, 'code');
        self::assertSame([1250, 850, 400], [$byCode['1']['planned_cents'], $byCode['1']['realized_cents'], $byCode['1']['open_cents']]);
        self::assertSame([1200, 'Ordinárias', false, 2], [$byCode['1.01']['planned_cents'], $byCode['1.01']['name'], $byCode['1.01']['analytic'], $byCode['1.01']['level']]);
        self::assertTrue($byCode['1.01.01']['analytic']);
        self::assertSame('Despesas', $byCode['2']['name']);
        self::assertSame([], FinancialReports::rollup([]));
    }

    public function testTotalsAndResults(): void
    {
        $totals = FinancialReports::totals([
            ['nature' => 'receita', 'planned_cents' => 1000, 'realized_cents' => 700, 'open_cents' => 300],
            ['nature' => 'despesa', 'planned_cents' => 400, 'realized_cents' => 400, 'open_cents' => 0],
        ]);
        self::assertSame(600, $totals['planned_result_cents']);
        self::assertSame(300, $totals['realized_result_cents']);
        self::assertSame(300, $totals['open_revenue_cents']);
    }

    public function testReconciliationShowsDifferenceInsteadOfHidingIt(): void
    {
        self::assertSame(['opening_cents' => 1000, 'received_cents' => 500, 'paid_cents' => 200, 'closing_cents' => 1300], FinancialReports::bankMovement(1000, 500, 200));
        $ok = FinancialReports::reconcile(500, 200, 300);
        self::assertTrue($ok['reconciled']);
        self::assertSame(0, $ok['difference_cents']);
        $off = FinancialReports::reconcile(500, 200, 250);
        self::assertFalse($off['reconciled']);
        self::assertSame(50, $off['difference_cents'], 'Pago sem conta bancária aparece como diferença.');
    }
}
