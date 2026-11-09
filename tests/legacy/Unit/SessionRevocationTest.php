<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Services\SessionRevocation;

/** ADR 0036: sair em um host encerra as sessões do usuário nos outros hosts. */
final class SessionRevocationTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        SessionRevocation::clearCache();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE user_session_revocations (user_id INTEGER PRIMARY KEY, revoked_at INTEGER NOT NULL)');
    }

    protected function tearDown(): void
    {
        SessionRevocation::clearCache();
    }

    public function testSessionsStartedBeforeLogoutAreRevokedAndLaterOnesAreNot(): void
    {
        $service = new SessionRevocation($this->pdo);
        self::assertFalse($service->isRevoked(7, 1_000));
        $service->revokeAll(7, 2_000);
        SessionRevocation::clearCache();
        $fresh = new SessionRevocation($this->pdo);
        self::assertTrue($fresh->isRevoked(7, 1_000), 'Sessão do outro host, aberta antes do logout.');
        self::assertTrue($fresh->isRevoked(7, 2_000));
        self::assertFalse($fresh->isRevoked(7, 2_001), 'Login feito depois do logout continua valendo.');
        self::assertFalse($fresh->isRevoked(8, 1_000), 'Outro usuário não é afetado.');
    }

    public function testSecondLogoutMovesTheMarkForward(): void
    {
        $service = new SessionRevocation($this->pdo);
        $service->revokeAll(7, 2_000);
        $service->revokeAll(7, 5_000);
        self::assertSame(1, (int)$this->pdo->query('SELECT COUNT(*) FROM user_session_revocations')->fetchColumn());
        SessionRevocation::clearCache();
        self::assertTrue((new SessionRevocation($this->pdo))->isRevoked(7, 4_000));
    }

    public function testMissingTableFailsOpen(): void
    {
        $broken = new SessionRevocation(new PDO('sqlite::memory:'));
        $broken->revokeAll(7, 2_000);
        SessionRevocation::clearCache();
        self::assertFalse((new SessionRevocation(new PDO('sqlite::memory:')))->isRevoked(7, 1_000));
    }

    public function testOnlyUserInitiatedLogoutsRevokeEverywhere(): void
    {
        $root = __DIR__ . '/../../../source/';
        foreach (['Controllers/Erp/Connect/Login.php', 'Controllers/Studio/Studio.php'] as $file) {
            self::assertStringContainsString('Auth::logout(true);', (string)file_get_contents($root . $file), $file);
        }
        $auth = (string)file_get_contents($root . 'Models/Auth.php');
        self::assertStringContainsString('->set(self::AUTH_AT, SessionRevocation::nowMs() + 1)', $auth);
        self::assertStringContainsString('isRevoked((int)$session->authUser', $auth);
    }

    public function testMigrationHasReversal(): void
    {
        $dir = __DIR__ . '/../../../database/migrations/';
        self::assertStringContainsString('CREATE TABLE IF NOT EXISTS user_session_revocations', (string)file_get_contents($dir . '20261109_0101_user_session_revocations.sql'));
        self::assertStringContainsString('DROP TABLE IF EXISTS user_session_revocations', (string)file_get_contents($dir . '20261109_0101_user_session_revocations.down.sql'));
    }
}
