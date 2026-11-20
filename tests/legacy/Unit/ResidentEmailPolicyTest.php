<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\ResidentChargeNotices;
use Source\Services\Erp\ResidentEmailPolicy as Policy;
use Source\Support\MailTemplate;

/** Morador recebe só cinco e-mails (ADR 0601): regras puras, modelos e ligações no código. */
final class ResidentEmailPolicyTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOnlyTheFourOutboxEmailsAreAllowed(): void
    {
        self::assertSame(['charge.due_today', 'charge.overdue', 'accountability.published', 'resident.invitation'], Policy::ALLOWED);
        foreach (['waitlist.called', 'invoice.issued', 'bank_charge.issued', 'agreement.created', 'agreement.breached', 'reservation.approved',
            'announcement.published', 'collection.reminder'] as $event) {
            self::assertFalse(Policy::allows($event), $event);
        }
        foreach (Policy::ALLOWED as $event) {
            self::assertContains($event, NotificationOutbox::EVENTS);
            self::assertArrayHasKey($event, NotificationDispatcher::NOTICES);
        }
    }

    public function testOverdueMilestones(): void
    {
        $expected = [0 => null, 9 => null, 10 => 10, 13 => 10, 14 => null, 15 => 15, 18 => 15, 19 => null, 29 => null, 30 => 30, 33 => 30, 34 => null,
            45 => 45, 48 => 45, 49 => null, 60 => 60, 63 => 60, 64 => null, 90 => null, 365 => null];
        foreach ($expected as $days => $milestone) {
            self::assertSame($milestone, Policy::overdueMilestone($days), "{$days} dias");
        }
        self::assertSame([10, 15, 30, 45, 60], Policy::MILESTONES);
    }

    public function testAccountabilityWaitsForTheFifth(): void
    {
        self::assertSame('2026-12-05 08:00:00', Policy::accountabilitySendAt(new DateTimeImmutable('2026-12-01 09:30')));
        self::assertSame('2026-12-05 08:00:00', Policy::accountabilitySendAt(new DateTimeImmutable('2026-12-04 23:59')));
        self::assertNull(Policy::accountabilitySendAt(new DateTimeImmutable('2026-12-05 07:00')));
        self::assertNull(Policy::accountabilitySendAt(new DateTimeImmutable('2026-12-10 12:00')));
        self::assertNull(Policy::accountabilitySendAt(new DateTimeImmutable('2026-12-18 12:00')), 'Atrasada sai na hora.');
    }

    public function testPayloadForOneAndManyCharges(): void
    {
        $one = ResidentChargeNotices::payload(['unit_id' => 5, 'unit_label' => '101', 'items' => [
            ['item_type' => 'charge', 'item_id' => 9, 'description' => 'Taxa 11/2026', 'amount_cents' => 45000, 'due_on' => '2026-11-20', 'digitable_line' => '0019 1234', 'days_late' => 0, 'milestone' => 0],
        ]]);
        self::assertSame(1, $one['items']);
        self::assertSame(45000, $one['amount_cents']);
        self::assertSame('2026-11-20', $one['due_on']);
        self::assertSame('0019 1234', $one['digitable_line']);
        $many = ResidentChargeNotices::payload(['unit_id' => 5, 'unit_label' => '101', 'items' => [
            ['item_type' => 'entry', 'item_id' => 1, 'description' => 'Taxa 09/2026', 'amount_cents' => 45000, 'due_on' => '2026-09-21', 'digitable_line' => null, 'days_late' => 60, 'milestone' => 60],
            ['item_type' => 'entry', 'item_id' => 2, 'description' => 'Taxa 10/2026', 'amount_cents' => 45000, 'due_on' => '2026-10-21', 'digitable_line' => null, 'days_late' => 30, 'milestone' => 30],
        ]]);
        self::assertSame(2, $many['items']);
        self::assertSame(90000, $many['amount_cents']);
        self::assertSame('', $many['due_on']);
        self::assertSame(60, $many['days_late']);
        self::assertStringContainsString("Taxa 09/2026 · R$ 450,00 · vencimento 21/09/2026 · 60 dias de atraso\nTaxa 10/2026", $many['lines']);
        self::assertSame(NotificationOutbox::cleanPayload($many), $many, 'Nada some na limpeza do payload.');
    }

    public function testTemplatesRender(): void
    {
        $mail = new MailTemplate();
        $vars = NotificationDispatcher::formatPayload(['amount_cents' => 45000, 'due_on' => '2026-11-20', 'items' => 1, 'description' => 'Taxa <b>11</b>',
            'unit_label' => '101', 'digitable_line' => '0019 1234', 'days_late' => 10, 'lines' => '']) + ['first_name' => 'Bia', 'condominium' => 'Alpha', 'app_link' => 'https://x.test/boletos'];
        $due = $mail->render('charge-due-today', $vars);
        self::assertStringContainsString('vence hoje', $due);
        self::assertStringContainsString('R$ 450,00', $due);
        self::assertStringContainsString('20/11/2026', $due);
        self::assertStringContainsString('0019 1234', $due);
        self::assertStringContainsString('Taxa &lt;b&gt;11&lt;/b&gt;', $due);
        $late = $mail->render('charge-overdue', $vars);
        self::assertStringContainsString('10 dias', $late);
        $many = $mail->render('charge-overdue', ['items' => '2', 'lines' => "Taxa A · R$ 1,00\nTaxa B · R$ 2,00"] + $vars);
        self::assertStringContainsString('2 boletos em atraso', $many);
        self::assertStringContainsString('Taxa B', $many);
    }

    public function testWiring(): void
    {
        self::assertStringContainsString('ResidentEmailPolicy::allows($eventType)', $this->file('source/Services/Erp/NotificationOutbox.php'));
        self::assertStringContainsString('ResidentEmailPolicy::DISABLED_REASON', $this->file('source/Services/Erp/NotificationDispatcher.php'));
        self::assertStringContainsString('ResidentEmailPolicy::accountabilitySendAt', $this->file('source/Services/Erp/AccountabilityLinks.php'));
        self::assertStringContainsString('tools/resident-charge-emails.php', $this->file('tools/hosting/cron.sh'));
        self::assertStringContainsString('tools/resident-charge-emails.php', $this->file('service/jobs/scheduler.sh'));
        self::assertStringContainsString("=== 'site'", $this->file('source/Services/Support/TicketService.php'));
        self::assertStringNotContainsString('new TwoFactorNotice', $this->file('source/Services/Resident/ResidentTwoFactor.php'));
    }
}
