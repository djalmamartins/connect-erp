<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0358: o cron da hospedagem não carrega o .env. O cron.sh lê de lá MAIL_QUEUE_SEND, ERP_MAIL_DELIVERY e PHP_BIN; com
 * ERP_MAIL_DELIVERY=production a fila de e-mails sai pelo SMTP. Roda o cron.sh de verdade numa cópia, com um "php" falso
 * que só anota o que seria executado.
 */
final class HostingCronEnvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/cron-env-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tools/hosting', 0777, true);
        copy(dirname(__DIR__, 3) . '/tools/hosting/cron.sh', $this->dir . '/tools/hosting/cron.sh');
        file_put_contents($this->dir . '/fake-php', "#!/bin/sh\necho \"rodou: \$*\"\n");
        chmod($this->dir . '/fake-php', 0755);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @param array<string, string> $env */
    private function minute(string $dotEnv, array $env = []): string
    {
        file_put_contents($this->dir . '/.env', $dotEnv);
        @unlink($this->dir . '/storage/logs/cron-minuto.log');
        $prefix = 'env -i PATH=' . escapeshellarg((string)getenv('PATH'));
        foreach ($env as $key => $value) {
            $prefix .= ' ' . $key . '=' . escapeshellarg($value);
        }
        exec($prefix . ' bash ' . escapeshellarg($this->dir . '/tools/hosting/cron.sh') . ' minuto 2>&1', $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
        self::assertFileExists($this->dir . '/storage/temp/heartbeat/minuto');
        return (string)@file_get_contents($this->dir . '/storage/logs/cron-minuto.log');
    }

    public function testMailQueueFollowsTheEnvFile(): void
    {
        $fake = 'PHP_BIN=' . $this->dir . "/fake-php\n";
        $lab = $this->minute($fake . "ERP_MAIL_DELIVERY=log\n");
        self::assertStringContainsString('rodou: tools/notifications-dispatch.php 50', $lab, 'PHP_BIN veio do .env');
        self::assertStringNotContainsString('sendEmail.php', $lab, 'no lab a fila não sai');

        self::assertStringContainsString('rodou: service/jobs/sendEmail.php', $this->minute($fake . "ERP_MAIL_DELIVERY=production\n"));
        self::assertStringContainsString('rodou: service/jobs/sendEmail.php', $this->minute($fake . "MAIL_QUEUE_SEND=\"1\"\n"));
        self::assertStringNotContainsString('sendEmail.php', $this->minute($fake . "ERP_MAIL_DELIVERY=production\n", ['ERP_MAIL_DELIVERY' => 'log']),
            'o ambiente vale sobre o .env');
    }

    public function testHostingGuideHasTheTalkWorker(): void
    {
        $guide = (string)file_get_contents(dirname(__DIR__, 3) . '/docs/process/HOSPEDAGEM.md');
        self::assertStringContainsString('tools/talk-worker.php', $guide);
    }
}
