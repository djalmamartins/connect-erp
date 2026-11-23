<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\StaffNotificationPolicy;

/** ADR 0645: sininho da equipe só com o básico. */
final class StaffNotificationPolicyTest extends TestCase
{
    public function testMonitoringNoticesAreSilenced(): void
    {
        self::assertTrue(StaffNotificationPolicy::silenced('Erro detectado no MovesOS'));
        self::assertTrue(StaffNotificationPolicy::silenced('Alteração no sistema'));
        self::assertTrue(StaffNotificationPolicy::silenced('Novo artigo: Como pagar o boleto'));
    }

    public function testWorkNoticesStay(): void
    {
        foreach (['Novo chamado SAC-20261122-0001', 'Nova resposta no chamado', 'Nova proposta pelo site', 'Novo compromisso na agenda',
            'Ação de cobrança para hoje', 'Prestação de contas atrasada', 'Item do checklist vencendo'] as $title) {
            self::assertFalse(StaffNotificationPolicy::silenced($title), $title);
        }
    }

    public function testSourcesNoLongerWriteSilencedNotices(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringNotContainsString('INSERT INTO notifications', (string)file_get_contents($root . '/source/Support/AppLogger.php'));
        self::assertStringNotContainsString('INSERT INTO notifications', (string)file_get_contents($root . '/source/Support/Audit.php'));
        self::assertStringNotContainsString('"Novo artigo: "', (string)file_get_contents($root . '/source/Controllers/Studio/Studio.php'));
    }
}
