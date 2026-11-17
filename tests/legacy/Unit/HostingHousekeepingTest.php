<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\HostingHousekeeping;

/** Faxina da hospedagem (ADR 0336): logs compactados e apagados pela data do nome, disco e certificado. */
final class HostingHousekeepingTest extends TestCase
{
    public function testLogPlanByDateInName(): void
    {
        $plan = HostingHousekeeping::logPlan([
            'movesos-2026-11-17.log', 'movesos-2026-11-16.log', 'movesos-2026-11-15.log', 'movesos-2026-11-01.log.gz',
            'movesos-2026-05-18.log.gz', 'movesos-2026-05-17.log', 'cron-minuto.log', 'restore-check.log', 'movesos-lixo.log',
        ], '2026-11-17');
        self::assertSame(['movesos-2026-11-15.log'], $plan['compress'], 'Hoje e ontem ficam abertos; .gz não compacta de novo.');
        self::assertSame(['movesos-2026-05-17.log'], $plan['delete'], 'Apaga só o que passou de 183 dias; outros logs não entram.');
    }

    public function testDiskAndCertificateProblems(): void
    {
        self::assertNull(HostingHousekeeping::diskProblem(80 * 1048576, 100));
        self::assertStringContainsString('90,0 GB dos 100 GB do plano (90%)', (string)HostingHousekeeping::diskProblem(90 * 1048576, 100));
        self::assertNull(HostingHousekeeping::diskProblem(90 * 1048576, 0));
        self::assertNull(HostingHousekeeping::certProblem(null, 'erp.exemplo'));
        self::assertNull(HostingHousekeeping::certProblem(30, 'erp.exemplo'));
        self::assertStringContainsString('vence em 14 dia(s)', (string)HostingHousekeeping::certProblem(14, 'erp.exemplo'));
        self::assertStringContainsString('venceu', (string)HostingHousekeeping::certProblem(-1, 'erp.exemplo'));
    }

    public function testCronRunsHousekeepingAndWatchdogReadsIt(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString('tools/hosting/housekeeping.php', (string)file_get_contents($root . '/tools/hosting/cron.sh'));
        $watchdog = (string)file_get_contents($root . '/tools/hosting/watchdog.php');
        self::assertStringContainsString("\$problems['disk']", $watchdog);
        self::assertStringContainsString("\$problems['cert']", $watchdog);
    }
}
