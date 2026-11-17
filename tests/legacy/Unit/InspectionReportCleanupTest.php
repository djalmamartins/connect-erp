<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\Inspections\InspectionReportCleanup;

/** PDFs do relatório de vistoria só ficam enquanto algum e-mail por sair precisa deles (ADR 0335). */
final class InspectionReportCleanupTest extends TestCase
{
    private const NOW = 1_800_000_000;

    public function testRemovableRule(): void
    {
        $old = self::NOW - InspectionReportCleanup::MIN_AGE - 1;
        $files = ['/r/1/a.pdf' => $old, '/r/1/b.pdf' => $old, '/r/1/c.pdf' => self::NOW - 60, '/r/1/foto.jpg' => $old];
        self::assertSame(['/r/1/b.pdf'], InspectionReportCleanup::removable($files, ['/r/1/a.pdf' => true], self::NOW));
    }

    public function testRunKeepsQueuedAndPhotosAndRemovesSent(): void
    {
        $root = sys_get_temp_dir() . '/insp-' . bin2hex(random_bytes(4));
        mkdir($root . '/reports/7', 0700, true);
        mkdir($root . '/7', 0700, true);
        $sent = $root . '/reports/7/1-aaa.pdf';
        $queued = $root . '/reports/7/2-bbb.pdf';
        $fresh = $root . '/reports/7/3-ccc.pdf';
        $photo = $root . '/7/foto.pdf';
        foreach ([$sent, $queued, $fresh, $photo] as $f) {
            file_put_contents($f, '%PDF');
            touch($f, $f === $fresh ? self::NOW - 10 : self::NOW - 200000);
        }
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE mail_queue (id INTEGER PRIMARY KEY, status TEXT, sent_at TEXT, cancelled_at TEXT, attachments_json TEXT)');
        $ins = $pdo->prepare('INSERT INTO mail_queue (status, sent_at, cancelled_at, attachments_json) VALUES (?, ?, NULL, ?)');
        $ins->execute(['sent', '2026-11-16 10:00:00', json_encode([$sent => 'vistoria.pdf'])]);
        $ins->execute(['retry', null, json_encode([$queued => 'vistoria.pdf'])]);
        try {
            $cleanup = new InspectionReportCleanup($pdo, $root);
            self::assertSame(['removed' => 1, 'kept' => 2, 'bytes' => 4], $cleanup->run(self::NOW, true));
            self::assertFileExists($sent, '--dry-run não apaga.');
            self::assertSame(['removed' => 1, 'kept' => 2, 'bytes' => 4], $cleanup->run(self::NOW));
            self::assertFileDoesNotExist($sent);
            self::assertFileExists($queued, 'E-mail em nova tentativa ainda precisa do anexo.');
            self::assertFileExists($fresh);
            self::assertFileExists($photo, 'Fora de reports/ nada é apagado.');
        } finally {
            exec('rm -rf ' . escapeshellarg($root));
        }
    }
}
