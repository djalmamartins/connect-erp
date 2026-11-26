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

    public function testAlertEmailAcceptsSeveralAddresses(): void
    {
        self::assertSame(['sac@connectcondominios.com.br', 'djalma.martins@moves.com.br'],
            HostingWatchdog::recipients('sac@connectcondominios.com.br, Djalma.Martins@moves.com.br;sac@connectcondominios.com.br'));
        self::assertSame(['a@b.com'], HostingWatchdog::recipients('invalido, a@b.com'));
        self::assertSame([], HostingWatchdog::recipients(''));
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

    public function testBankCertificateWarnsBeforeAndAfterExpiry(): void
    {
        $day = 86400;
        $row = static fn (int $id, ?string $on): array => ['id' => $id, 'adapter' => 'inter', 'environment' => 'production',
            'certificate_expires_on' => $on, 'account' => 'Conta <Inter>', 'condominium' => 'Ed. Aurora'];
        $now = self::NOW;
        $rows = [
            $row(1, date('Y-m-d', $now + 40 * $day)),
            $row(2, date('Y-m-d', $now + 10 * $day)),
            $row(3, date('Y-m-d', $now - 2 * $day)),
            $row(4, null),
            $row(5, date('Y-m-d H:i:s', $now + 29 * $day)),
        ];
        $problems = HostingWatchdog::bankCertProblems($rows, $now);
        self::assertSame(['bankcert:2', 'bankcert-expired:3', 'bankcert:5'], array_keys($problems));
        self::assertStringContainsString('Banco Inter (produção, Ed. Aurora · Conta <Inter>) vence em 10 dia(s)', $problems['bankcert:2']);
        self::assertStringContainsString('venceu em', $problems['bankcert-expired:3']);
        self::assertStringContainsString('Configurações › Integrações', $problems['bankcert:2']);
        $cora = HostingWatchdog::bankCertProblems([['id' => 9, 'adapter' => 'cora', 'environment' => 'sandbox', 'certificate_expires_on' => date('Y-m-d', $now), 'account' => null, 'condominium' => null]], $now);
        self::assertSame(['bankcert:9'], array_keys($cora));
        self::assertStringContainsString('Cora (teste) vence em 0 dia(s)', $cora['bankcert:9']);

        // A contagem de dias muda todo dia, mas a chave não: o e-mail não repete a cada hora.
        $sent = ['hash' => HostingWatchdog::hash($problems), 'sent_at' => $now];
        self::assertFalse(HostingWatchdog::due(HostingWatchdog::bankCertProblems($rows, $now + $day), $sent, $now + 3600));
        // Quando vence de fato, a chave muda e sai aviso novo.
        self::assertTrue(HostingWatchdog::due(HostingWatchdog::bankCertProblems($rows, $now + 11 * $day), $sent, $now + 11 * $day));
        $watchdog = (string)file_get_contents(dirname(__DIR__, 3) . '/tools/hosting/watchdog.php');
        self::assertStringContainsString('HostingWatchdog::bankCertProblems(', $watchdog);
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
