<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\Auth;
use Source\Services\LoginThrottle;

/**
 * Issue #7, risco 5: limite de falhas de login/2FA por conta e por IP no banco (ADR 0023).
 * SQLite em memória no lugar do MariaDB; a migration real é conferida por texto.
 */
final class LoginThrottleTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE auth_throttle_events (id INTEGER PRIMARY KEY, scope TEXT, subject_hash TEXT, created_at INTEGER)');
    }

    public function testAccountIsBlockedAfterMaxFailuresInsideWindowOnly(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        [$max, $window] = LoginThrottle::LIMITS[LoginThrottle::LOGIN]['account'];
        $subjects = LoginThrottle::subjects('Ana@Example.com', null);
        $now = 1_800_000_000;
        for ($i = 0; $i < $max - 1; $i++) {
            $throttle->fail(LoginThrottle::LOGIN, $subjects, $now);
        }
        self::assertFalse($throttle->blocked(LoginThrottle::LOGIN, $subjects, $now));
        $throttle->fail(LoginThrottle::LOGIN, $subjects, $now);
        self::assertTrue($throttle->blocked(LoginThrottle::LOGIN, $subjects, $now));
        // Mesma conta digitada com outra caixa continua bloqueada; outro escopo não.
        self::assertTrue($throttle->blocked(LoginThrottle::LOGIN, LoginThrottle::subjects(' ana@example.COM ', null), $now));
        self::assertFalse($throttle->blocked(LoginThrottle::TWO_FACTOR, $subjects, $now));
        self::assertFalse($throttle->blocked(LoginThrottle::LOGIN, $subjects, $now + $window + 1));
    }

    public function testIpLimitCoversManyAccountsAndNewSessions(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        [$max] = LoginThrottle::LIMITS[LoginThrottle::LOGIN]['ip'];
        $now = 1_800_000_000;
        for ($i = 0; $i < $max; $i++) {
            $throttle->fail(LoginThrottle::LOGIN, LoginThrottle::subjects("user{$i}@example.com", '203.0.113.9'), $now);
        }
        self::assertTrue($throttle->blocked(LoginThrottle::LOGIN, LoginThrottle::subjects('nova@example.com', '203.0.113.9'), $now));
        self::assertFalse($throttle->blocked(LoginThrottle::LOGIN, LoginThrottle::subjects('nova@example.com', '198.51.100.1'), $now));
    }

    public function testSuccessClearsAccountButNotIp(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        $subjects = LoginThrottle::subjects('ana@example.com', '203.0.113.9');
        $throttle->fail(LoginThrottle::LOGIN, $subjects, time());
        $throttle->clear(LoginThrottle::LOGIN, $subjects);
        $rows = $this->pdo->query('SELECT subject_hash FROM auth_throttle_events')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([$subjects['ip']], $rows);
    }

    public function testOldEventsArePrunedAndNothingIsStoredInPlainText(): void
    {
        $throttle = new LoginThrottle($this->pdo);
        $now = 1_800_000_000;
        $throttle->fail(LoginThrottle::LOGIN, LoginThrottle::subjects('velho@example.com', null), $now - 90_000);
        $throttle->fail(LoginThrottle::LOGIN, LoginThrottle::subjects('ana@example.com', '203.0.113.9'), $now);
        $rows = $this->pdo->query('SELECT subject_hash, created_at FROM auth_throttle_events')->fetchAll(PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($now, (int)$row['created_at']);
            self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row['subject_hash']);
        }
    }

    public function testInvalidIpIsIgnoredAndMissingTableDoesNotBlock(): void
    {
        self::assertSame([], LoginThrottle::subjects('', 'not-an-ip'));
        $broken = new LoginThrottle(new PDO('sqlite::memory:'));
        $subjects = LoginThrottle::subjects('ana@example.com', '203.0.113.9');
        $broken->fail(LoginThrottle::LOGIN, $subjects);
        self::assertFalse($broken->blocked(LoginThrottle::LOGIN, $subjects));
    }

    public function testThrottleAccountNormalizesLikeAttempt(): void
    {
        self::assertSame('ana@example.com', Auth::throttleAccount(' Ana@Example.com '));
        self::assertSame('12345678909', Auth::throttleAccount('123.456.789-09'));
    }

    public function testMigrationCreatesTableWithReversal(): void
    {
        $dir = __DIR__ . '/../../../database/migrations/';
        $up = (string)file_get_contents($dir . '20261108_0901_auth_throttle_events.sql');
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS auth_throttle_events', $up);
        self::assertStringContainsString('idx_auth_throttle_subject (scope, subject_hash, created_at)', $up);
        self::assertStringContainsString('DROP TABLE IF EXISTS auth_throttle_events', (string)file_get_contents($dir . '20261108_0901_auth_throttle_events.down.sql'));
    }
}
