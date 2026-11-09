<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\OperationalDashboard;

/** Dashboard: situações calculadas pela data e próxima manutenção (ADR 0053). */
final class OperationalDashboardTest extends TestCase
{
    public function testValidity(): void
    {
        self::assertSame('vencido', OperationalDashboard::validity('2026-11-08', '2026-11-09'));
        self::assertSame('a_renovar', OperationalDashboard::validity('2026-11-09', '2026-11-09'), 'Vence hoje: a renovar.');
        self::assertSame('a_renovar', OperationalDashboard::validity('2026-12-09', '2026-11-09'), '30 dias.');
        self::assertSame('vigente', OperationalDashboard::validity('2026-12-10', '2026-11-09'));
        self::assertSame('vigente', OperationalDashboard::validity(null, '2026-11-09'), 'Contrato sem fim.');
    }

    public function testLateMaintenance(): void
    {
        self::assertTrue(OperationalDashboard::isLate('active', '2026-11-08', '2026-11-09'));
        self::assertFalse(OperationalDashboard::isLate('active', '2026-11-09', '2026-11-09'));
        self::assertFalse(OperationalDashboard::isLate('done', '2026-10-01', '2026-11-09'), 'Concluída não atrasa.');
        self::assertFalse(OperationalDashboard::isLate('running', '2026-10-01', '2026-11-09'), 'Em execução não conta como atrasada.');
    }

    public function testNextDateByFrequency(): void
    {
        self::assertSame('2026-12-15', OperationalDashboard::nextDate('2026-11-15', 'mensal'));
        self::assertSame('2027-02-28', OperationalDashboard::nextDate('2026-11-30', 'trimestral'), 'Dia limitado ao fim do mês.');
        self::assertSame('2027-05-30', OperationalDashboard::nextDate('2026-11-30', 'semestral'), 'Mantém o dia quando o mês tem.');
        self::assertSame('2027-11-15', OperationalDashboard::nextDate('2026-11-15', 'anual'));
        self::assertNull(OperationalDashboard::nextDate('2026-11-15', 'unica'));
    }
}
