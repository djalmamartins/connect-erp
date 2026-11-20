<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Api\V1\IntegrationTokens;
use Source\Services\Talk\TalkTokens;
use Source\Support\IpRateLimit;

/** Token de integração com escopos, validade, IPs e limite (ADR 0623): regras puras, rotas, tela e migration. */
final class IntegrationTokenScopesTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    protected function tearDown(): void
    {
        TalkTokens::setCurrent(null);
        parent::tearDown();
    }

    public function testScopesAreNormalizedInScreenOrder(): void
    {
        self::assertSame(['people.read', 'charges.read', 'tickets.write'], TalkTokens::normalizeScopes(['tickets.write', 'charges.read', 'people.read', 'people.read']));
        self::assertSame(['condominiums.read', 'residents.read'], TalkTokens::normalizeScopes(' residents.read ,condominiums.read,admin.all'));
        self::assertSame(TalkTokens::LEGACY_SCOPES, TalkTokens::normalizeScopes(TalkTokens::LEGACY_SCOPES));
        foreach ([[], '', 'admin.all', null, 42, [['people.read']]] as $bad) {
            try {
                TalkTokens::normalizeScopes($bad);
                self::fail('Sem escopo válido tem de dar erro.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        // Só tickets.write é escrita; todo o resto é leitura.
        $writes = array_keys(array_filter(TalkTokens::SCOPES, static fn (array $s): bool => $s[1] === 'write'));
        self::assertSame(['tickets.write'], $writes);
        self::assertCount(count(TalkTokens::SCOPES), IntegrationTokens::scopes());
    }

    public function testAllowedIpsAreValidated(): void
    {
        self::assertNull(TalkTokens::normalizeIps(''));
        self::assertNull(TalkTokens::normalizeIps(null));
        self::assertSame('10.0.0.5,2001:db8::1', TalkTokens::normalizeIps(' 10.0.0.5 ; 2001:db8::1, 10.0.0.5 '));
        $this->expectException(\InvalidArgumentException::class);
        TalkTokens::normalizeIps('10.0.0.5, talk.connect');
    }

    public function testUsableChecksRevokedExpiryAndIp(): void
    {
        $now = strtotime('2026-11-20 12:00:00');
        self::assertTrue(TalkTokens::usable(['revoked_at' => null, 'expires_at' => null, 'allowed_ips' => null], '1.2.3.4', $now));
        self::assertFalse(TalkTokens::usable(['revoked_at' => '2026-11-19 10:00:00', 'expires_at' => null, 'allowed_ips' => null], '1.2.3.4', $now));
        self::assertTrue(TalkTokens::usable(['revoked_at' => null, 'expires_at' => '2026-11-20 12:00:01', 'allowed_ips' => null], null, $now));
        self::assertFalse(TalkTokens::usable(['revoked_at' => null, 'expires_at' => '2026-11-20 12:00:00', 'allowed_ips' => null], null, $now), 'Venceu na hora exata.');
        self::assertTrue(TalkTokens::usable(['revoked_at' => null, 'expires_at' => null, 'allowed_ips' => '1.2.3.4,5.6.7.8'], '5.6.7.8', $now));
        self::assertFalse(TalkTokens::usable(['revoked_at' => null, 'expires_at' => null, 'allowed_ips' => '1.2.3.4'], '9.9.9.9', $now));
        self::assertFalse(TalkTokens::usable(['revoked_at' => null, 'expires_at' => null, 'allowed_ips' => '1.2.3.4'], null, $now));
    }

    public function testAllowsNeedsATokenInTheRequest(): void
    {
        TalkTokens::setCurrent(null);
        self::assertFalse(TalkTokens::allows('people.read'));
        TalkTokens::setCurrent(['id' => 1, 'scopes' => ['people.read']]);
        self::assertTrue(TalkTokens::allows('people.read'));
        self::assertFalse(TalkTokens::allows('tickets.write'));
    }

    public function testRatePerTokenCountsEachTokenApart(): void
    {
        $dir = sys_get_temp_dir() . '/talk-rate-' . bin2hex(random_bytes(4));
        $limit = new IpRateLimit($dir);
        $now = 1_800_000_000;
        for ($i = 0; $i < TalkTokens::RATE[0]; $i++) {
            self::assertFalse(TalkTokens::overRate(7, $limit, $now));
        }
        self::assertTrue(TalkTokens::overRate(7, $limit, $now), 'O pedido 121 no mesmo minuto passa do limite.');
        self::assertFalse(TalkTokens::overRate(8, $limit, $now), 'Outro token tem a sua própria conta.');
        self::assertFalse(TalkTokens::overRate(7, $limit, $now + TalkTokens::RATE[1] + 1), 'Janela nova, conta nova.');
        array_map('unlink', glob($dir . '/*') ?: []);
        @rmdir($dir);
    }

    public function testRoutesOpenApiScreenAndMigrationAreWired(): void
    {
        $index = $this->file('index.php');
        self::assertStringContainsString('$route->get("/talk/me", "Talk:me", null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
        foreach (['get("/integration-tokens", "IntegrationTokens:index"', 'post("/integration-tokens", "IntegrationTokens:store"', 'post("/integration-tokens/{id}/revoke", "IntegrationTokens:revoke"'] as $route) {
            self::assertStringContainsString('$route->' . $route . ', null, \\Source\\Support\\Middleware\\ApiAuthenticated::class);', $index);
        }
        $openapi = $this->file('docs/api/openapi.yaml');
        foreach (['/talk/me:', '/integration-tokens:', '/integration-tokens/{id}/revoke:', 'insufficient_scope'] as $needle) {
            self::assertStringContainsString($needle, $openapi);
        }
        $controller = $this->file('source/Controllers/Api/V1/Talk.php');
        foreach (["agent('people.read')", "agent('charges.read')", "agent('tickets.write')"] as $needle) {
            self::assertStringContainsString($needle, $controller);
        }
        $page = $this->file('container/erp/connect/v1/pages/settings/integrations.php');
        self::assertStringContainsString('data-erp-integration-tokens', $page);
        self::assertStringContainsString('data-integration-token-revoke', $page);
        self::assertStringContainsString('data-erp-integration-tokens', $this->file('container/erp/connect/v1/assets/erp.min.js'), 'Bundle regenerado.');
        $migration = $this->file('database/migrations/20261120_2100_integration_token_scopes.sql');
        self::assertStringContainsString("DEFAULT 'people.read,charges.read,tickets.write'", $migration, 'O token que já existe continua com o que o Talk usa.');
        foreach (['`expires_at`', '`allowed_ips`', '`last_ip`'] as $column) {
            self::assertStringContainsString($column, $migration);
        }
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261120_2100_integration_token_scopes.down.sql');
    }
}
