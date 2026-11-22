<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Source\Services\Support\TicketProtocol;

/** Regras puras do protocolo no formato do Connect Talk (ADR 0636). */
final class TicketProtocolTest extends TestCase
{
    public function testFormatIsPrefixDateAndFourDigitSequence(): void
    {
        $day = new DateTimeImmutable('2026-11-16 10:00:00');
        self::assertSame('SAC202611160008', TicketProtocol::format(TicketProtocol::KIND_TICKET, $day, 8));
        self::assertSame('OCO202611160001', TicketProtocol::format(TicketProtocol::KIND_OCCURRENCE, $day, 1));
        self::assertSame('SAC2026111612345', TicketProtocol::format(TicketProtocol::KIND_TICKET, $day, 12345), 'Passou de 9999 no dia: 5 dígitos.');
        self::assertSame('SAC202611160001', TicketProtocol::format('qualquer', $day, 0), 'Tipo desconhecido vira chamado; sequência mínima 1.');
    }

    public function testNormalizeAcceptsNewAndOldFormatsTypedLoosely(): void
    {
        self::assertSame('SAC202611160008', TicketProtocol::normalize(' sac 2026-1116-0008 '));
        self::assertSame('OCO202611160003', TicketProtocol::normalize('oco.20261116.0003'));
        self::assertSame('CH261109A1B2', TicketProtocol::normalize('ch261109a1b2'), 'Antigo do Help Desk continua valendo.');
        self::assertSame('OC0000000042', TicketProtocol::normalize('OC0000000042'), 'Antigo das ocorrências continua valendo.');
        foreach (['', 'vazamento', 'SAC2026', 'XYZ202611160008', 'CH261109A1B2X', '20261116-00001'] as $other) {
            self::assertNull(TicketProtocol::normalize($other), $other);
        }
    }

    public function testKindComesFromThePrefix(): void
    {
        self::assertSame(TicketProtocol::KIND_OCCURRENCE, TicketProtocol::kindOf('OCO202611160003'));
        self::assertSame(TicketProtocol::KIND_OCCURRENCE, TicketProtocol::kindOf('OC0000000042'));
        self::assertSame(TicketProtocol::KIND_TICKET, TicketProtocol::kindOf('SAC202611160008'));
        self::assertSame(TicketProtocol::KIND_TICKET, TicketProtocol::kindOf('CH261109A1B2'));
        self::assertTrue(TicketProtocol::isValid('SAC202611160008'));
        self::assertFalse(TicketProtocol::isValid('sac202611160008'), 'Gravado sempre em maiúsculas.');
    }
}
