<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\NotificationDispatcher;
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

    public function testWaitlistTemplateEscapes(): void
    {
        $html = (new MailTemplate())->render('waitlist-called', ['first_name' => '<b>Bia</b>', 'area' => 'Salão & Festa', 'date' => '20/11/2026', 'condominium' => 'Alpha']);
        self::assertStringContainsString('&lt;b&gt;Bia&lt;/b&gt;', $html);
        self::assertStringContainsString('Salão &amp; Festa', $html);
        self::assertStringContainsString('20/11/2026', $html);
    }
}
