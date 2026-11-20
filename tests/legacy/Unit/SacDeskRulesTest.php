<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Source\Services\Support\SacDesk;

/** Regras puras do balão do SAC (ADR 0629): horário de atendimento e o texto mostrado no site. */
final class SacDeskRulesTest extends TestCase
{
    public function testHoursComeFromTheSettingAndFallBackWhenInvalid(): void
    {
        self::assertSame(['08:00', '18:00'], SacDesk::hours('08:00-18:00'));
        self::assertSame(['07:30', '17:00'], SacDesk::hours(' 07:30-17:00 '));
        foreach (['', '8h-18h', '18:00-08:00', '25:00-26:00', '09:00-09:00'] as $invalid) {
            self::assertSame(['08:00', '18:00'], SacDesk::hours($invalid), $invalid);
        }
        self::assertSame('segunda a sexta, das 8h às 18h', SacDesk::hoursLabel('08:00-18:00'));
        self::assertSame('segunda a sexta, das 7h30 às 17h', SacDesk::hoursLabel('07:30-17:00'));
    }

    public function testOpenOnlyOnBusinessDaysInsideTheWindow(): void
    {
        self::assertTrue(SacDesk::isOpen(new DateTimeImmutable('2026-11-25 08:00'), '08:00-18:00'));
        self::assertTrue(SacDesk::isOpen(new DateTimeImmutable('2026-11-25 17:59'), '08:00-18:00'));
        self::assertFalse(SacDesk::isOpen(new DateTimeImmutable('2026-11-25 07:59'), '08:00-18:00'));
        self::assertFalse(SacDesk::isOpen(new DateTimeImmutable('2026-11-25 18:00'), '08:00-18:00'));
        self::assertFalse(SacDesk::isOpen(new DateTimeImmutable('2026-11-29 10:00'), '08:00-18:00'), 'domingo');
        self::assertFalse(SacDesk::isOpen(new DateTimeImmutable('2026-11-20 10:00'), '08:00-18:00'), 'Consciência Negra, feriado nacional');
    }

    public function testWidgetShowsTeamWhatsappAndOutOfHoursWithoutPersonalData(): void
    {
        $root = dirname(__DIR__, 3);
        $widget = (string)file_get_contents($root . '/container/web/support/v1/pages/widget.php');
        foreach (['SAC — Atendimento Connect', 'Falar no WhatsApp', 'Abrir chamado', 'Acompanhar protocolo', 'Central de Ajuda', 'Estamos fora do horário', 'Seus dados estão protegidos'] as $text) {
            self::assertStringContainsString($text, $widget);
        }
        self::assertStringNotContainsString('#2563eb', $widget, 'o azul antigo saiu: cinza-claro com dourado');
        $script = (string)file_get_contents($root . '/container/shared/assets/js/helpdesk-widget.js');
        self::assertStringContainsString('/suporte/widget/equipe', $script);
        self::assertStringContainsString("credentials:'omit'", $script, 'o botão não manda cookie do site para saber quem está online');
        self::assertStringContainsString('"/widget/equipe", "Support:widgetTeam"', (string)file_get_contents($root . '/container/web/support/v1/routes.php'));
    }
}
