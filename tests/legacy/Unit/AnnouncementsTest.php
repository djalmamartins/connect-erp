<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\NotificationDispatcher;
use Source\Support\MailTemplate;

/** Comunicação pela outbox (ADR 0059): agendamento, texto, públicos e modelos. */
final class AnnouncementsTest extends TestCase
{
    public function testSendAtWindow(): void
    {
        $now = new \DateTimeImmutable('2026-11-09 10:00:00');
        self::assertSame('2026-11-09 10:00:00', Announcements::sendAt(null, $now), 'Vazio = agora.');
        self::assertSame('2026-11-20 08:30:00', Announcements::sendAt('2026-11-20T08:30', $now));
        foreach (['2026-11-09 09:00', '2027-02-01 08:00', '20/11/2026 08:00', '2026-11-31 08:00'] as $bad) {
            try {
                Announcements::sendAt($bad, $now);
                self::fail("Aceitou {$bad}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTextIsCleanedAndBounded(): void
    {
        self::assertSame("Linha 1\nLinha dois", Announcements::text("  Linha 1\r\nLinha \t dois\x07 ", 5, 100, 'o texto'), 'Controle sai, quebra de linha fica.');
        $this->expectException(InvalidArgumentException::class);
        Announcements::text('curto', 10, 100, 'o texto');
    }

    /** ADR 0065: assunto e motivo em uma linha; unidades escolhidas normalizadas e com teto. */
    public function testSubjectIsSingleLineAndUnitIdsAreCapped(): void
    {
        self::assertSame('Obra Bcc: x@y.test', Announcements::line("Obra\r\nBcc: x@y.test", 5, 120, 'o assunto'));
        self::assertSame('A B', Announcements::line("A\u{2028}B", 1, 120, 'o assunto'));
        self::assertNull(Announcements::unitIds(null));
        self::assertSame([3, 7], Announcements::unitIds([3, '7', '3']));
        foreach ([[], 'x', ['1a'], [0], [-2], array_fill(0, Announcements::MAX_UNITS + 1, 1)] as $bad) {
            try {
                Announcements::unitIds($bad);
                self::fail('Aceitou ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNoticesAudiencesAndCollectionUsesBillingOptIn(): void
    {
        self::assertSame('notices', NotificationDispatcher::NOTICES['announcement.published']['audience'], 'Comunicado respeita a recusa (notices_opt_out).');
        self::assertSame('billing', NotificationDispatcher::NOTICES['collection.reminder']['audience'], 'Cobrança só com o opt-in billing_by_email.');
        self::assertSame(7, Announcements::COLLECTION_COOLDOWN_DAYS);
    }

    public function testTemplatesEscape(): void
    {
        $mail = new MailTemplate();
        $html = $mail->render('announcement', ['first_name' => 'Bia', 'subject' => 'Obra <b>na</b> garagem', 'body' => "Dia 20.\n<script>x</script>", 'condominium' => 'Alpha']);
        self::assertStringContainsString('Obra &lt;b&gt;na&lt;/b&gt; garagem', $html);
        self::assertStringContainsString('Dia 20.<br', $html, 'Quebra de linha vira <br>.');
        self::assertStringNotContainsString('<script>x</script>', $html);
        $html = $mail->render('collection-reminder', ['first_name' => 'Bia', 'unit_label' => '101', 'entries' => '2', 'amount' => 'R$ 1.000,00',
            'oldest_due_on' => '10/09/2026', 'as_of' => '09/11/2026', 'condominium' => '<i>Alpha</i>']);
        self::assertStringContainsString('R$ 1.000,00', $html);
        self::assertStringContainsString('&lt;i&gt;Alpha&lt;/i&gt;', $html);
    }
}
