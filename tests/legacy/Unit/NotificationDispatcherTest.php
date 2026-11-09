<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;
use Source\Support\MailTemplate;

/** Fila de avisos: modo de entrega seguro, espera entre tentativas, máscara e modelo (ADR 0055). */
final class NotificationDispatcherTest extends TestCase
{
    public function testProductionOnlyInProductionWithExplicitConfig(): void
    {
        self::assertSame('production', NotificationDispatcher::deliveryMode('production', 'production', null));
        self::assertSame('log', NotificationDispatcher::deliveryMode('production', 'log', null), 'Produção sem configuração explícita: só log.');
        self::assertSame('log', NotificationDispatcher::deliveryMode('production', '', 'teste@mailpit.local'));
        foreach (['development', 'testing', 'staging', 'lab'] as $env) {
            self::assertNotSame('production', NotificationDispatcher::deliveryMode($env, 'production', 'teste@mailpit.local'), "{$env} nunca envia para morador.");
        }
        self::assertSame('sandbox', NotificationDispatcher::deliveryMode('development', 'production', 'teste@mailpit.local'), 'Fora de produção, cai para a caixa de teste.');
        self::assertSame('sandbox', NotificationDispatcher::deliveryMode('development', 'sandbox', 'teste@mailpit.local'));
        self::assertSame('log', NotificationDispatcher::deliveryMode('development', 'sandbox', null), 'Sandbox sem caixa de teste: só log.');
        self::assertSame('log', NotificationDispatcher::deliveryMode('development', 'sandbox', 'não-é-email'));
    }

    public function testBackoffAndMask(): void
    {
        self::assertSame([1, 5, 15, 60, 240, 240], array_map([NotificationDispatcher::class, 'backoffMinutes'], [1, 2, 3, 4, 5, 9]));
        self::assertSame('a**@exemplo.com', NotificationDispatcher::mask('ana@exemplo.com'));
        self::assertSame('j******@x.org', NotificationDispatcher::mask('joaquimsilva@x.org'), 'No máximo 6 asteriscos.');
        self::assertSame('b*@x.org', NotificationDispatcher::mask('b@x.org'));
    }

    public function testEveryEventHasNoticeAndTemplate(): void
    {
        self::assertSame(NotificationOutbox::EVENTS, array_keys(NotificationDispatcher::NOTICES), 'Todo evento da outbox tem aviso, e vice-versa.');
        $mail = new MailTemplate();
        foreach (NotificationDispatcher::NOTICES as $event => $notice) {
            self::assertTrue($mail->exists($notice['template']), "Modelo de {$event} existe em container/send.");
            self::assertContains($notice['audience'], ['unit', 'billing', 'notices', 'resident']);
        }
        foreach (['invoice.issued', 'bank_charge.issued', 'agreement.created', 'agreement.breached'] as $billing) {
            self::assertSame('billing', NotificationDispatcher::NOTICES[$billing]['audience'], "{$billing} respeita o opt-in de cobrança por e-mail.");
        }
    }

    public function testFormatPayload(): void
    {
        $out = NotificationDispatcher::formatPayload(['unit_id' => 7, 'amount_cents' => 123456, 'due_on' => '2026-11-10', 'competence' => '2026-11',
            'installments' => 3, 'fee_cents' => null, 'type_name' => 'Taxa']);
        self::assertSame(['amount' => 'R$ 1.234,56', 'due_on' => '10/11/2026', 'competence' => '11/2026', 'installments' => '3', 'fee_cents' => '', 'type_name' => 'Taxa'], $out);
        self::assertArrayNotHasKey('unit_id', $out, 'Ids não entram no modelo.');
    }

    /** Todos os modelos renderizam com as variáveis do payload e escapam o conteúdo. */
    public function testAllTemplatesRenderAndEscape(): void
    {
        $xss = '<script>x</script>';
        $base = ['first_name' => $xss, 'condominium' => 'Alpha', 'unit_label' => '101'];
        $cases = [
            'invoice-issued' => ['type_name' => 'Taxa', 'competence' => '11/2026', 'due_on' => '10/11/2026', 'amount' => 'R$ 500,00'],
            'bank-charge-issued' => ['description' => 'Taxa 2026-11', 'due_on' => '10/11/2026', 'amount' => 'R$ 500,00', 'digitable_line' => '00190.00009 01234.567890'],
            'agreement-created' => ['number' => '12', 'installments' => '3', 'installment' => 'R$ 343,34', 'total' => 'R$ 1.030,00', 'first_due_on' => '10/12/2026', 'last_due_on' => '10/02/2027'],
            'agreement-breached' => ['number' => '12', 'total' => 'R$ 1.030,00', 'overdue_installments' => '2'],
            'reservation-approved' => ['area' => 'Salão', 'date' => '20/11/2026', 'starts' => '18:00', 'ends' => '22:00', 'fee' => 'R$ 80,00'],
            'resident-invitation' => ['invitation_link' => 'https://connect.test/convite/abc', 'expires_days' => '7'],
        ];
        $mail = new MailTemplate();
        foreach ($cases as $template => $vars) {
            $html = $mail->render($template, $vars + $base);
            self::assertStringNotContainsString($xss, $html, "{$template} escapa as variáveis.");
            self::assertStringContainsString('&lt;script&gt;', $html);
            foreach (['amount', 'total', 'date', 'digitable_line', 'invitation_link'] as $key) {
                if (isset($vars[$key])) {
                    self::assertStringContainsString($vars[$key], $html, "{$template} mostra {$key}.");
                }
            }
        }
        self::assertStringNotContainsString('Taxa da reserva', $mail->render('reservation-approved', ['area' => 'Salão', 'date' => '20/11/2026'] + $base), 'Sem taxa nem horário, o modelo ainda renderiza.');
    }

    public function testReservationNotifyRule(): void
    {
        $definition = Definitions::get('reservations');
        $values = ['status' => 'confirmed', 'unit_id' => 3, 'common_area_id' => 4, 'reserved_on' => '2026-11-20', 'starts_time' => '18:00:00', 'ends_time' => '22:00', 'fee_cents' => null];
        $notice = RegistryService::notification($definition, ['status' => 'active'], $values);
        self::assertSame('reservation.approved', $notice['event']);
        self::assertSame(['unit_id' => 3, 'common_area_id' => 4, 'date' => '2026-11-20', 'starts' => '18:00', 'ends' => '22:00', 'fee_cents' => null], $notice['payload']);
        self::assertNull(RegistryService::notification($definition, null, $values), 'Criar já confirmada (área livre) não é aprovação.');
        self::assertNull(RegistryService::notification($definition, ['status' => 'confirmed'], $values), 'Salvar de novo não repete o aviso.');
        self::assertNull(RegistryService::notification($definition, ['status' => 'inactive'], $values));
        self::assertNull(RegistryService::notification($definition, ['status' => 'active'], ['status' => 'inactive'] + $values));
        self::assertNull(RegistryService::notification(Definitions::get('units'), ['status' => 'active'], ['status' => 'confirmed']));
    }

    public function testWaitlistTemplateEscapes(): void
    {
        $html = (new MailTemplate())->render('waitlist-called', ['first_name' => '<b>Bia</b>', 'area' => 'Salão & Festa', 'date' => '20/11/2026', 'condominium' => 'Alpha']);
        self::assertStringContainsString('&lt;b&gt;Bia&lt;/b&gt;', $html);
        self::assertStringContainsString('Salão &amp; Festa', $html);
        self::assertStringContainsString('20/11/2026', $html);
    }
}
