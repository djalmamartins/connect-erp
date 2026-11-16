<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\HostingHealth;
use Source\Support\HostingWatchdog;

/** Monitoramento da hospedagem (ADR 0333): sinal de vida do cron, /health e o vigia que avisa por e-mail. */
final class HostingMonitorTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testEvaluateStatusesAndUnknownIsNeutral(): void
    {
        self::assertSame('ready', HostingHealth::evaluate([], self::NOW)['status'], 'Lab sem cron: tudo unknown, sem alarme.');
        $result = HostingHealth::evaluate([
            'minuto' => ['at' => self::NOW - 120, 'failures' => 0],
            'hora' => ['at' => self::NOW - 3600, 'failures' => 1],
            'backup' => ['at' => self::NOW - 93601, 'failures' => 0],
        ], self::NOW);
        self::assertSame('degraded', $result['status']);
        self::assertSame(['minuto' => 'ready', 'hora' => 'failing', 'dia' => 'unknown', 'backup' => 'stale'], array_map(static fn ($g) => $g['status'], $result['groups']));
        self::assertSame(['status' => 'degraded', 'checks' => ['minuto' => 'ready', 'hora' => 'failing', 'dia' => 'unknown', 'backup' => 'stale']], HostingHealth::publicChecks($result));
        self::assertSame('stale', HostingHealth::evaluate(['minuto' => ['at' => self::NOW - 601, 'failures' => 0]], self::NOW)['groups']['minuto']['status']);
    }

    public function testReadParsesHeartbeatFiles(): void
    {
        $dir = sys_get_temp_dir() . '/hb-' . bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir . '/minuto', "1799999999 0\n");
        file_put_contents($dir . '/hora', "1799990000 2\n");
        file_put_contents($dir . '/dia', "lixo\n");
        file_put_contents($dir . '/outro', "1799990000 0\n");
        try {
            self::assertSame(['minuto' => ['at' => 1799999999, 'failures' => 0], 'hora' => ['at' => 1799990000, 'failures' => 2]], HostingHealth::read($dir));
        } finally {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }

    public function testWatchdogProblemsDedupAndRecovery(): void
    {
        $eval = static fn (int $age): array => HostingHealth::evaluate(['backup' => ['at' => self::NOW - $age, 'failures' => 0], 'minuto' => ['at' => self::NOW, 'failures' => 0]], self::NOW);
        $first = HostingWatchdog::problems($eval(100000), 3);
        self::assertSame(['stale:backup', 'notifications'], array_keys($first));
        self::assertStringContainsString('backup diário não roda há 1 dia(s)', $first['stale:backup']);
        self::assertTrue(HostingWatchdog::due($first, [], self::NOW), 'Problema novo: avisa.');
        $sent = ['hash' => HostingWatchdog::hash($first), 'sent_at' => self::NOW];
        $later = HostingWatchdog::problems($eval(110000), 5);
        self::assertNotSame($first, $later);
        self::assertFalse(HostingWatchdog::due($later, $sent, self::NOW + 3600), 'Mesmo problema com outro número: não repete na hora seguinte.');
        self::assertTrue(HostingWatchdog::due($later, $sent, self::NOW + HostingWatchdog::REPEAT), 'Repete a cada 12 horas.');
        self::assertTrue(HostingWatchdog::due([], $sent, self::NOW + 60), 'Voltou ao normal: avisa uma vez.');
        self::assertFalse(HostingWatchdog::due([], ['hash' => '', 'sent_at' => self::NOW], self::NOW + 60));
        self::assertFalse(HostingWatchdog::due([], [], self::NOW));
        $mail = HostingWatchdog::message(['x' => '<b>falhou</b>'], 'erp.exemplo');
        self::assertStringContainsString('&lt;b&gt;falhou', $mail['body']);
        self::assertStringContainsString('erp.exemplo (1)', $mail['subject']);
    }

    public function testCronWritesHeartbeatAndRunsWatchdog(): void
    {
        $cron = (string)file_get_contents(dirname(__DIR__, 3) . '/tools/hosting/cron.sh');
        self::assertStringContainsString('storage/temp/heartbeat', $cron);
        self::assertStringContainsString('failures=$((failures + 1))', $cron);
        self::assertStringContainsString('tools/hosting/watchdog.php', $cron);
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertStringContainsString('HostingHealth::publicChecks()', $index);
    }
}
