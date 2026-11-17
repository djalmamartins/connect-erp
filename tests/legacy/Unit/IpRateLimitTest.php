<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\IpRateLimit;

final class IpRateLimitTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/ip-rate-limit-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function testBlocksIpAfterLimitEvenWithoutSession(): void
    {
        $limit = new IpRateLimit($this->dir);
        $now = time();
        for ($i = 1; $i <= 3; $i++) {
            self::assertFalse($limit->hit('resident_app_login', '203.0.113.7', 3, 60, $now), "pedido {$i}");
        }
        self::assertTrue($limit->hit('resident_app_login', '203.0.113.7', 3, 60, $now + 10));
        // Outro IP e outra chave têm contadores próprios.
        self::assertFalse($limit->hit('resident_app_login', '203.0.113.8', 3, 60, $now + 10));
        self::assertFalse($limit->hit('erp_ticket_open', '203.0.113.7', 3, 60, $now + 10));
    }

    public function testWindowReopensAfterSeconds(): void
    {
        $limit = new IpRateLimit($this->dir);
        $now = time();
        self::assertFalse($limit->hit('k', '198.51.100.1', 1, 60, $now));
        self::assertTrue($limit->hit('k', '198.51.100.1', 1, 60, $now + 59));
        self::assertFalse($limit->hit('k', '198.51.100.1', 1, 60, $now + 61));
    }

    public function testStoresOnlyHashedKeyAndIp(): void
    {
        $limit = new IpRateLimit($this->dir);
        $limit->hit('api_help_articles', '192.0.2.44', 5, 60);
        $files = glob($this->dir . '/*') ?: [];
        self::assertCount(1, $files);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', basename($files[0]));
        self::assertStringNotContainsString('192.0.2.44', (string)file_get_contents($files[0]));
    }

    public function testPurgeRemovesOldCounters(): void
    {
        $limit = new IpRateLimit($this->dir);
        $limit->hit('k', '192.0.2.1', 5, 60);
        $file = (glob($this->dir . '/*') ?: [])[0];
        touch($file, time() - 2 * 86400);
        self::assertSame(1, $limit->purge());
        self::assertSame([], glob($this->dir . '/*') ?: []);
    }

    public function testIpLimitLeavesRoomForSharedAddresses(): void
    {
        self::assertSame(20, IpRateLimit::ipLimitFor(2));
        self::assertSame(40, IpRateLimit::ipLimitFor(8));
    }

    public function testUnwritableDirectoryNeverBlocks(): void
    {
        $limit = new IpRateLimit('/proc/nao-existe/rate-limit');
        self::assertFalse($limit->hit('k', '192.0.2.1', 0, 60));
    }

    /** Teste de carga (ADR 0348): 10 processos ao mesmo tempo, 30 pedidos cada; o contador não perde pedidos e o limite é exato. */
    public function testConcurrentProcessesNeverExceedTheLimit(): void
    {
        $source = dirname(__DIR__, 3) . '/source/Support/IpRateLimit.php';
        $code = 'require ' . var_export($source, true) . '; $l = new Source\\Support\\IpRateLimit(' . var_export($this->dir, true) . '); $ok = 0;'
            . ' for ($i = 0; $i < 30; $i++) { if (!$l->hit("login", "203.0.113.9", 100, 600)) { $ok++; } } echo $ok;';
        $processes = [];
        for ($p = 0; $p < 10; $p++) {
            $processes[] = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w']], $pipes);
            $outputs[$p] = $pipes[1];
        }
        $allowed = 0;
        foreach ($processes as $p => $process) {
            $allowed += (int)stream_get_contents($outputs[$p]);
            fclose($outputs[$p]);
            proc_close($process);
        }
        self::assertSame(100, $allowed);
        $files = glob($this->dir . '/*') ?: [];
        self::assertCount(1, $files);
        self::assertSame(300, json_decode((string)file_get_contents($files[0]), true)['count']);
    }
}
