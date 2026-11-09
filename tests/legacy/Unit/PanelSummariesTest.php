<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\PanelSummaries;

/** Painéis: ocupação; outbox: payload limpo e eventos conhecidos (ADR 0054). */
final class PanelSummariesTest extends TestCase
{
    public function testOccupancy(): void
    {
        self::assertSame(['units' => 40, 'occupied' => 30, 'vacant' => 10, 'rate' => 75.0], PanelSummaries::occupancy(40, 30));
        self::assertSame(['units' => 0, 'occupied' => 0, 'vacant' => 0, 'rate' => 0.0], PanelSummaries::occupancy(0, 0));
        self::assertSame(5, PanelSummaries::occupancy(5, 9)['occupied'], 'Nunca mais ocupadas que unidades.');
    }

    public function testOutboxPayloadDropsSecretsAndStructures(): void
    {
        $clean = NotificationOutbox::cleanPayload(['resident_name' => 'Bia', 'unit_id' => 3, 'token' => 'x', 'api_key' => 'y', 'senha' => 'z',
            'nested' => ['a' => 1], 'date' => '2026-11-20', 'long' => str_repeat('a', 900)]);
        self::assertSame(['resident_name', 'unit_id', 'date', 'long'], array_keys($clean));
        self::assertSame(500, mb_strlen($clean['long']));
    }

    public function testUnknownEventIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new NotificationOutbox(new \PDO('sqlite::memory:')))->record('cobranca.enviada', 'k', 1, 't', 1, [], null);
    }
}
