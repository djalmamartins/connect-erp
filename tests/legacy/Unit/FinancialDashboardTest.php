<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\FinancialDashboard;

/** Regras puras do dashboard com dados reais (ADR 0157). */
final class FinancialDashboardTest extends TestCase
{
    public function testWindowEndsOnTheCompetenceAndCrossesTheYear(): void
    {
        self::assertSame(['2025-10', '2025-11', '2025-12', '2026-01', '2026-02', '2026-03'], FinancialDashboard::window('2026-03'));
        self::assertSame(['2026-11'], FinancialDashboard::window('2026-11', 1));
        $this->expectException(InvalidArgumentException::class);
        FinancialDashboard::window('2026-13');
    }

    public function testCompetenceFallsBackToTheCurrentMonth(): void
    {
        self::assertSame('2026-09', FinancialDashboard::competenceOrCurrent('2026-09', '2026-11-10'));
        self::assertSame('2026-11', FinancialDashboard::competenceOrCurrent('2026-9', '2026-11-10'));
        self::assertSame('2026-11', FinancialDashboard::competenceOrCurrent(['x'], '2026-11-10'));
        self::assertSame('2026-11', FinancialDashboard::competenceOrCurrent(null, '2026-11-10'));
    }

    public function testLabelsRatesAndStatus(): void
    {
        self::assertSame('Nov', FinancialDashboard::monthLabel('2026-11'));
        self::assertSame('março de 2026', FinancialDashboard::monthLabel('2026-03', true));
        self::assertNull(FinancialDashboard::rate(10, 0));
        self::assertSame(33.3, FinancialDashboard::rate(1, 3));
        self::assertSame('Sem lançamentos', FinancialDashboard::status(null));
        self::assertSame('Em dia', FinancialDashboard::status(5.0));
        self::assertSame('Atenção', FinancialDashboard::status(5.1));
        self::assertSame('Atenção', FinancialDashboard::status(15.0));
        self::assertSame('Crítico', FinancialDashboard::status(15.1));
    }

    public function testAgo(): void
    {
        $now = strtotime('2026-11-10 12:00:00');
        self::assertSame('agora', FinancialDashboard::ago('2026-11-10 11:59:30', $now));
        self::assertSame('há 5 min', FinancialDashboard::ago('2026-11-10 11:55:00', $now));
        self::assertSame('há 3 h', FinancialDashboard::ago('2026-11-10 09:00:00', $now));
        self::assertSame('ontem', FinancialDashboard::ago('2026-11-09 20:00:00', $now));
        self::assertSame('01/11', FinancialDashboard::ago('2026-11-01 08:00:00', $now));
    }

    public function testActivityTitleAgreesInGender(): void
    {
        self::assertSame('Lançamento cadastrado', FinancialDashboard::activityTitle('erp_entries', 'create'));
        self::assertSame('Fatura alterada', FinancialDashboard::activityTitle('erp_invoices', 'update'));
        self::assertSame('Reserva excluída', FinancialDashboard::activityTitle('erp_reservations', 'delete'));
    }

    public function testActivityTablesAreAFixedWhitelist(): void
    {
        foreach (array_keys(FinancialDashboard::ACTIVITY_ENTITIES) as $table) {
            self::assertMatchesRegularExpression('/^erp_[a-z_]+$/', $table, 'Nome de tabela entra na SQL: só da lista fixa.');
        }
    }
}
