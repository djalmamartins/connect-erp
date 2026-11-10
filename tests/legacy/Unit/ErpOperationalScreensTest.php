<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\PendingReservations;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/**
 * Telas do Operacional (ADR 0051 e 0070): Agendamentos Pendentes (AP-114) com confirmar e recusar
 * pelo motor, e o chamado do Help Desk na ocorrência (AP-112).
 */
final class ErpOperationalScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testPendingReservationsOwnTemplateOnlyWithRealData(): void
    {
        self::assertNull(Panel::ownTemplate('AP-114', ['pendingReservations' => null]));
        self::assertSame('pages/operational/pending-reservations', Panel::ownTemplate('AP-114', ['pendingReservations' => ['data' => []]]));
        self::assertSame('erp.reservations.view', Panel::pagePermissions()['AP-114']);
    }

    public function testFormInputRoundTripsThroughTheValidator(): void
    {
        $definition = Definitions::get('reservations');
        $record = ['id' => 31, 'condominium_id' => 1, 'common_area_id' => 4, 'unit_id' => 9, 'resident_name' => 'Ana', 'reserved_on' => '2026-11-14',
            'starts_time' => '14:00', 'ends_time' => '18:00', 'fee_cents' => 125050, 'notes' => null, 'status' => 'active'];
        $input = ['status' => 'confirmed'] + PendingReservations::formInput($definition, $record);
        self::assertSame('1.250,50', $input['fee_cents']);
        self::assertSame('', $input['notes']);
        [$values, $errors] = RegistryValidator::validate($definition, $input);
        self::assertSame([], $errors);
        self::assertSame(125050, $values['fee_cents']);
        self::assertSame('confirmed', $values['status']);
        self::assertSame(4, (int)$values['common_area_id']);
        self::assertSame('2026-11-14', $values['reserved_on']);
        self::assertNull($values['notes']);
    }

    public function testFormInputKeepsAReservationWithoutFeeOrHours(): void
    {
        $definition = Definitions::get('reservations');
        $record = ['condominium_id' => 1, 'common_area_id' => 4, 'unit_id' => 9, 'resident_name' => 'Ana', 'reserved_on' => '2026-11-14',
            'starts_time' => null, 'ends_time' => null, 'fee_cents' => null, 'notes' => 'x'];
        [$values, $errors] = RegistryValidator::validate($definition, ['status' => 'inactive'] + PendingReservations::formInput($definition, $record));
        self::assertSame([], $errors);
        self::assertNull($values['fee_cents']);
        self::assertNull($values['starts_time']);
    }

    public function testDecisionsAndRoutes(): void
    {
        self::assertSame(['confirmar' => 'confirmed', 'recusar' => 'inactive'], PendingReservations::DECISIONS);
        $statuses = Definitions::get('reservations')['status'];
        foreach (PendingReservations::DECISIONS as $status) {
            self::assertArrayHasKey($status, $statuses);
        }
        $routes = $this->file('container/erp/connect/v1/routes.php');
        self::assertStringContainsString('"/operacional/agendamentos-pendentes/{id}/confirmar", "PendingReservations:confirm"', $routes);
        self::assertStringContainsString('"/operacional/agendamentos-pendentes/{id}/recusar", "PendingReservations:refuse"', $routes);
        $controller = $this->file('source/Controllers/Erp/Connect/PendingReservations.php');
        self::assertStringContainsString("csrf_verify(\$data)", $controller);
        self::assertStringContainsString("erp.reservations.manage", $controller);
        self::assertStringContainsString("\$record['status'] !== 'active'", $controller);
    }

    public function testOccurrenceTicketPanel(): void
    {
        $host = $_SERVER['HTTP_HOST'] ?? null;
        $_SERVER['HTTP_HOST'] = 'erp.connect.lab';
        try {
            $open = \Source\Controllers\Erp\Connect\Registry::ticketData(5, ['ticket_id' => null], true);
        self::assertTrue($open['canOpen']);
        self::assertNull($open['href']);
        self::assertStringEndsWith('/api/v1/occurrences/5/ticket', $open['api']);
        self::assertStringEndsWith('/operacional/chamados/__ID__', $open['hrefPattern']);
        $done = \Source\Controllers\Erp\Connect\Registry::ticketData(5, ['ticket_id' => '77'], true);
        self::assertFalse($done['canOpen']);
        self::assertSame(77, $done['ticketId']);
        self::assertStringEndsWith('/operacional/chamados/77', (string)$done['href']);
            $closed = \Source\Controllers\Erp\Connect\Registry::ticketData(5, ['ticket_id' => null], false);
        } finally {
            if ($host === null) {
                unset($_SERVER['HTTP_HOST']);
            } else {
                $_SERVER['HTTP_HOST'] = $host;
            }
        }
        self::assertFalse($closed['canOpen']);

        $js = $this->file('container/erp/connect/v1/assets/js/erp.js');
        self::assertStringContainsString("\$\$('[data-erp-occurrence-ticket]')", $js);
        self::assertStringContainsString("call('POST', box.getAttribute('data-api'))", $js);
        self::assertStringContainsString('data-erp-occurrence-ticket', $this->file('container/erp/connect/v1/assets/erp.min.js'));
    }

    public function testDashboardKpisFromServiceCards(): void
    {
        $kpis = Panel::dashboardKpis(['receivables_overdue_cents' => 1234567, 'receivables_week_cents' => 0, 'payables_week_cents' => 100,
            'maintenances_late' => 0, 'maintenances_week' => 2, 'occurrences_open' => 1, 'reservations_pending' => 1]);
        self::assertSame(['receivables_overdue', 'payables_week', 'maintenances_late', 'occurrences_open'], array_column($kpis, 'key'));
        self::assertSame('R$ 12.345,67', $kpis[0]['value']);
        self::assertSame('warning', $kpis[1]['tone']);
        self::assertSame('', $kpis[2]['tone']);
        self::assertSame('2 previstas para os próximos 7 dias', $kpis[2]['meta']);
        self::assertSame('1 reserva aguardando aprovação', $kpis[3]['meta']);
        self::assertSame('R$ 0,00', Panel::dashboardKpis([])[0]['value']);
    }

    public function testRecordActionsFollowTheServiceRules(): void
    {
        $waitlist = \Source\Controllers\Erp\Connect\Registry::recordAction('reservations', 8, ['status' => 'inactive']);
        self::assertSame('waitlist', $waitlist['kind'] ?? null);
        self::assertStringEndsWith('/api/v1/reservations/8/call-waitlist', $waitlist['api']);
        self::assertNull(\Source\Controllers\Erp\Connect\Registry::recordAction('reservations', 8, ['status' => 'confirmed']));
        $next = \Source\Controllers\Erp\Connect\Registry::recordAction('maintenances', 8, ['status' => 'done', 'frequency' => 'anual']);
        self::assertStringEndsWith('/api/v1/maintenances/8/next', $next['api'] ?? '');
        self::assertNull(\Source\Controllers\Erp\Connect\Registry::recordAction('maintenances', 8, ['status' => 'done', 'frequency' => 'unica']));
        self::assertNull(\Source\Controllers\Erp\Connect\Registry::recordAction('maintenances', 8, ['status' => 'running', 'frequency' => 'anual']));
        self::assertNull(\Source\Controllers\Erp\Connect\Registry::recordAction('occurrences', 8, ['status' => 'inactive']));
        self::assertStringContainsString("\$\$('[data-erp-record-action]')", $this->file('container/erp/connect/v1/assets/js/erp.js'));
    }

    public function testPanelsUseTheirOwnTemplatesOnlyWhenReady(): void
    {
        // AP-002 foi fundido na visão geral do condomínio (ADR 0154): sem template próprio no catálogo.
        self::assertNull(Panel::ownTemplate('AP-002', ['condoPanelReady' => true]));
        self::assertNull(Panel::ownTemplate('AP-003', ['unitPanelReady' => false]));
        self::assertSame('pages/condominiums/unit-panel', Panel::ownTemplate('AP-003', ['unitPanelReady' => true]));
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString('(new PanelSummaries())->condominium($user, $id)', $this->file('source/Controllers/Erp/Connect/Condominiums.php'));
        self::assertStringContainsString("(new \\Source\\Services\\Erp\\PanelSummaries())->unit(\$user, (int)\$selected)", $panel);
        self::assertStringContainsString("Access::can('erp.units.view', \$user)", $panel);
    }
}
