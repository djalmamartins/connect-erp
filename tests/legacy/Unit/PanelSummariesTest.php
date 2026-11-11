<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\OperationalDashboard;
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

    /** ADR 0061: o painel exige a permissão de cada módulo que mostra. */
    public function testPanelRequiresEveryModulePermission(): void
    {
        self::assertSame([], PanelSummaries::missing('condominium', static fn (string $p): bool => true));
        self::assertSame(['erp.bank_accounts.view', 'erp.agreements.view', 'erp.closing.view'],
            PanelSummaries::missing('condominium', static fn (string $p): bool => $p === 'erp.units.view'));
        self::assertSame(['erp.residents.view'], PanelSummaries::missing('unit', static fn (string $p): bool => $p !== 'erp.residents.view'));
        self::assertContains('erp.unit_documents.view', PanelSummaries::REQUIRED['unit']);
    }

    /** ADR 0155: endereço da unidade na ficha da pessoa. */
    public function testUnitAddressLine(): void
    {
        self::assertNull(PanelSummaries::address(['street' => ' ', 'city' => 'Campinas'], 'A-101'));
        self::assertSame('Rua A, 5 · Bloco 2 · unidade A-101 · Centro · Campinas/SP · CEP 13010-000',
            PanelSummaries::address(['street' => 'Rua A', 'number' => '5', 'complement' => 'Bloco 2', 'district' => 'Centro', 'city' => 'Campinas', 'state' => 'SP', 'zip_code' => '13010000'], 'A-101'));
        self::assertSame('Rua A · Campinas', PanelSummaries::address(['street' => 'Rua A', 'city' => 'Campinas', 'zip_code' => '123'], ''));
    }

    /** ADR 0061: cartões com valores do financeiro saem do dashboard de quem não tem erp.entries.view. */
    public function testDashboardHidesFinanceCardsWithoutPermission(): void
    {
        $cards = ['maintenances_late' => 2, 'receivables_overdue_cents' => 100, 'receivables_week_cents' => 5, 'payables_week_cents' => 7];
        self::assertSame($cards, OperationalDashboard::withoutFinance($cards, true));
        self::assertSame(['maintenances_late' => 2], OperationalDashboard::withoutFinance($cards, false));
    }
}
