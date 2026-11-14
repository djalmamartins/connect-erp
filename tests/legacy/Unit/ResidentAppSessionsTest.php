<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use DateTimeImmutable;
use MovesOSTests\TestCase;
use Source\Models\Auth;
use Source\Models\User;
use Source\Services\Resident\ResidentAppSessions as Sessions;

/** Sessão do app nativo do morador (ADR 0303): regras puras, rotas, OpenAPI e usuário só da requisição. */
final class ResidentAppSessionsTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    protected function tearDown(): void
    {
        Auth::actingAs(null);
        parent::tearDown();
    }

    public function testTokenIsRandomHexAndOnlyWellFormedTokensAreHashed(): void
    {
        $a = Sessions::newToken();
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, Sessions::newToken());
        self::assertSame(hash('sha256', $a), Sessions::hashOf($a));
        foreach ([null, '', 'abc', strtoupper($a), $a . '0', 123, ['x']] as $bad) {
            self::assertNull(Sessions::hashOf($bad));
        }
    }

    public function testBearerHeaderParsing(): void
    {
        $token = Sessions::newToken();
        self::assertSame($token, Sessions::bearerFrom('Bearer ' . $token));
        self::assertSame($token, Sessions::bearerFrom('  bearer ' . strtoupper($token) . ' '));
        self::assertNull(Sessions::bearerFrom(null));
        self::assertNull(Sessions::bearerFrom('Basic ' . $token));
        self::assertNull(Sessions::bearerFrom('Bearer ' . substr($token, 1)));
        self::assertNull(Sessions::bearerFrom('Bearer ' . $token . ' extra'));
    }

    public function testDeviceNameIsCleanedAndCut(): void
    {
        self::assertSame('App Connect Morador (ios)', Sessions::deviceName('App Connect Morador (ios)'));
        self::assertSame('Celular da Ana', Sessions::deviceName("<b>Celular</b>\x00 da Ana"));
        self::assertSame(80, mb_strlen((string)Sessions::deviceName(str_repeat('á', 200))));
        self::assertNull(Sessions::deviceName('   '));
        self::assertNull(Sessions::deviceName(['x']));
    }

    public function testStateExpiresByAgeIdlenessAndRevocation(): void
    {
        $now = new DateTimeImmutable('2026-11-14 10:00:00');
        $row = ['revoked_at' => null, 'expires_at' => '2027-02-12 10:00:00', 'last_used_at' => '2026-11-10 08:00:00'];
        self::assertSame('active', Sessions::state($row, $now));
        self::assertSame('revoked', Sessions::state(['revoked_at' => '2026-11-13 09:00:00'] + $row, $now));
        self::assertSame('expired', Sessions::state(['expires_at' => '2026-11-14 10:00:00'] + $row, $now));
        self::assertSame('idle', Sessions::state(['last_used_at' => '2026-10-15 10:00:00'] + $row, $now));
        self::assertSame('active', Sessions::state(['last_used_at' => '2026-10-15 10:00:01'] + $row, $now));
    }

    public function testActingUserIsOnlyForTheRequestAndNeverTouchesTheSession(): void
    {
        $user = new User();
        $user->id = 42;
        Auth::actingAs($user);
        self::assertSame($user, Auth::user());
        self::assertSame(42, Auth::actingUserId());
        self::assertArrayNotHasKey('authUser', $_SESSION);
        Auth::actingAs(null);
        self::assertNull(Auth::actingUserId());
    }

    public function testRoutesOpenApiAndMigrationAreWired(): void
    {
        $index = $this->file('index.php');
        self::assertStringContainsString('$route->post("/resident/app-sessions", "ResidentAppSessions:store");', $index);
        self::assertStringContainsString('$route->post("/resident/app-sessions/verify", "ResidentAppSessions:verify");', $index);
        self::assertStringContainsString('$route->delete("/resident/app-sessions/current", "ResidentAppSessions:destroy");', $index);
        $openapi = $this->file('docs/api/openapi.yaml');
        foreach (['/resident/app-sessions:', '/resident/app-sessions/verify:', '/resident/app-sessions/current:', 'residentApp:', 'scheme: bearer'] as $needle) {
            self::assertStringContainsString($needle, $openapi);
        }
        $migration = $this->file('database/migrations/20261114_0902_resident_app_tokens.sql');
        self::assertStringContainsString('`token_hash` char(64) NOT NULL', $migration);
        self::assertStringNotContainsString('`token` ', $migration, 'O token em si nunca vai para o banco.');
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261114_0902_resident_app_tokens.down.sql');
    }

    public function testSecretsNeverReachLogsOrAudit(): void
    {
        $source = $this->file('source/Services/Resident/ResidentAppSessions.php');
        self::assertDoesNotMatchRegularExpression('/AppLogger::[a-z]+\([^;]*\$(token|challenge|password)\b/', $source);
        self::assertDoesNotMatchRegularExpression('/Audit::record\([^;]*\$(token|challenge|password)\b/', $source);
        self::assertStringContainsString("hash('sha256', \$token)", $source);
    }
}
