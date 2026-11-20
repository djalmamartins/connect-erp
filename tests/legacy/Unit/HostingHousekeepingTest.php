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

    /** ADR 0375: cópia local do OneDrive sai depois de 7 dias sem uso; envio interrompido, depois de 1 dia. */
    public function testOneDriveCachePlan(): void
    {
        $now = 1_800_000_000;
        $day = 86400;
        $plan = HostingHousekeeping::cachePlan([
            '/c/documents/1/2026/novo.pdf' => $now - $day,
            '/c/documents/1/2026/limite.pdf' => $now - 7 * $day,
            '/c/documents/1/2026/velho.pdf' => $now - 7 * $day - 1,
            '/c/inspections/1/2/a.jpg.ab12.part' => $now - $day - 1,
            '/c/inspections/1/2/b.jpg.cd34.part' => $now - 3600,
        ], $now);
        self::assertSame(['/c/documents/1/2026/velho.pdf', '/c/inspections/1/2/a.jpg.ab12.part'], $plan);
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/tools/hosting/housekeeping.php');
        self::assertStringContainsString("storage/cache/onedrive", $script);
        self::assertStringContainsString("str_starts_with(\$path, \$cache . '/')", $script, 'só apaga dentro do cache');
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
