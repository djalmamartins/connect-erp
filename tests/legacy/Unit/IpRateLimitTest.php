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
}
