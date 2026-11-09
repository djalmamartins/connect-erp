<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Occurrences;
use Source\Services\Erp\Registry\Definitions;

/** Operacional: definições, reservas sobre a área comum e ocorrência → chamado (ADR 0051). */
final class OperationalRegistriesTest extends TestCase
{
    public function testDefinitionsFollowTheCatalog(): void
    {
        $expected = ['maintenances' => 'AP-110', 'occurrences' => 'AP-112', 'reservations' => 'AP-113', 'waitlist' => 'AP-115',
            'insurances' => 'AP-118', 'contracts' => 'AP-119', 'moves' => 'AP-120', 'move_settings' => 'AP-121'];
        foreach ($expected as $slug => $page) {
            $definition = Definitions::get($slug);
            self::assertSame([$page, 'operacional', 'required'], [$definition['page'], $definition['group'], $definition['scope']], $slug);
            self::assertArrayHasKey('active', $definition['status'], $slug);
        }
        self::assertSame('common_areas', Definitions::get('reservations')['fields']['common_area_id']['ref'], 'Reserva sobre a área comum (AP-008).');
        self::assertSame('common_areas', Definitions::get('waitlist')['fields']['common_area_id']['ref']);
        self::assertTrue(Definitions::get('contracts')['fields']['supplier_id']['required']);
        foreach (['maintenances', 'occurrences', 'insurances', 'contracts'] as $slug) {
            self::assertSame('document_id', Definitions::get($slug)['attachment'] ?? null, "{$slug}: anexo pelo Disco Virtual");
        }
        self::assertSame(['ticket_id'], Definitions::get('occurrences')['computed'], 'ticket_id só pelo Help Desk.');
        self::assertArrayNotHasKey('ticket_id', Definitions::get('occurrences')['fields']);
    }

    public function testOverlapRules(): void
    {
        $reservation = Definitions::get('reservations')['no_overlap'][0];
        self::assertSame([['common_area_id', 'reserved_on'], 'starts_time', 'ends_time', ['inactive']],
            [$reservation['per'], $reservation['start'], $reservation['end'], $reservation['ignore_status']]);
        $move = Definitions::get('moves')['no_overlap'][0];
        self::assertSame(['condominium_id', 'scheduled_on', 'period'], $move['per']);
        self::assertArrayNotHasKey('start', $move, 'Mudança: um agendamento por período e dia.');
    }

    public function testOccurrenceBecomesTicketInput(): void
    {
        $input = Occurrences::ticketInput(['id' => 7, 'condominium_id' => 3, 'unit_id' => 12, 'subject' => 'Vazamento', 'priority' => 'alta',
            'description' => 'Vazamento no teto da garagem, bloco B.', 'occurred_on' => '2026-11-09']);
        self::assertSame(['Vazamento', 'high', 3, 12, 'Ocorrência'], [$input['subject'], $input['priority'], $input['condominium_id'], $input['unit_id'], $input['category']]);
        self::assertStringContainsString('Ocorrência #7', $input['message']);
        $short = Occurrences::ticketInput(['id' => 8, 'condominium_id' => 3, 'unit_id' => null, 'subject' => 'Gás', 'priority' => 'media',
            'description' => 'Cheiro', 'occurred_on' => '2026-11-09']);
        self::assertSame(['Ocorrência: Gás', 'medium', null], [$short['subject'], $short['priority'], $short['unit_id']], 'Ajusta ao mínimo do Help Desk.');
        self::assertGreaterThanOrEqual(10, mb_strlen($short['message']));
    }
}
