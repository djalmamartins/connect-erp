<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Support\TicketService;
use Source\Services\Talk\TalkHelpdesk;
use Source\Services\Talk\TalkTokens;

/** Integração com o Connect Talk (ADR 0308): regras puras, rotas, OpenAPI e migration. */
final class TalkIntegrationTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testTokenFormatAndBearerParsing(): void
    {
        $token = TalkTokens::newToken();
        self::assertMatchesRegularExpression('/^ctk_[0-9a-f]{64}$/', $token);
        self::assertNotSame($token, TalkTokens::newToken());
        self::assertSame(hash('sha256', $token), TalkTokens::hashOf($token));
        foreach ([null, '', 'ctk_abc', substr($token, 4), strtoupper($token), $token . '0', 42] as $bad) {
            self::assertNull(TalkTokens::hashOf($bad));
        }
        self::assertSame($token, TalkTokens::bearerFrom('Bearer ' . $token));
        self::assertSame($token, TalkTokens::bearerFrom('  Bearer   ' . $token . ' '));
        self::assertNull(TalkTokens::bearerFrom(null));
        self::assertNull(TalkTokens::bearerFrom('Basic ' . $token));
        self::assertNull(TalkTokens::bearerFrom('Bearer ' . substr($token, 4)), 'O token do app do morador não serve aqui.');
    }

    public function testAllowedIpList(): void
    {
        self::assertTrue(TalkTokens::ipAllowed('10.0.0.5', ''));
        self::assertTrue(TalkTokens::ipAllowed(null, ' '));
        self::assertTrue(TalkTokens::ipAllowed('10.0.0.5', '10.0.0.4, 10.0.0.5'));
        self::assertFalse(TalkTokens::ipAllowed('10.0.0.6', '10.0.0.4,10.0.0.5'));
        self::assertFalse(TalkTokens::ipAllowed(null, '10.0.0.4'));
    }

    public function testPhoneVariantsCoverCountryCodeAndNinthDigit(): void
    {
        $variants = TalkHelpdesk::phoneVariants('5531999998888');
        foreach (['31999998888', '5531999998888', '3199998888', '553199998888'] as $expected) {
            self::assertContains($expected, $variants);
        }
        self::assertContains('31999998888', TalkHelpdesk::phoneVariants('553199998888'), 'Sem o nono dígito também acha o celular com nove.');
        self::assertSame(['3133334444', '553133334444'], TalkHelpdesk::phoneVariants('(31) 3333-4444'), 'Fixo não ganha nono dígito.');
        self::assertSame([], TalkHelpdesk::phoneVariants('123,abc,'));
        self::assertCount(4, TalkHelpdesk::phoneVariants('5531999998888,31999998888'), 'Variantes repetidas saem uma vez só.');
        self::assertSame([], TalkHelpdesk::phoneVariants(str_repeat('1,', 6) . '5531999998888'), 'Mais de 6 números: o resto é ignorado.');
    }

    public function testSamePhoneAndMask(): void
    {
        self::assertTrue(TalkHelpdesk::samePhone('5531999998888', '3199998888'));
        self::assertFalse(TalkHelpdesk::samePhone('5531999998888', '31999997777'));
        self::assertFalse(TalkHelpdesk::samePhone('', '31999998888'));
        self::assertSame('*********8888', TalkHelpdesk::maskPhone('5531999998888'));
    }

    public function testTalkIsATicketOriginWithExternalRef(): void
    {
        self::assertContains('talk', TicketService::ORIGINS);
        $data = TicketService::normalize(['subject' => 'WhatsApp 1', 'message' => 'Conversa de WhatsApp.', 'external_ref' => '20261115-00001 x!'], 'talk');
        self::assertSame('20261115-00001x', $data['external_ref']);
    }

    public function testRoutesOpenApiAndMigrationAreWired(): void
    {
        $index = $this->file('index.php');
        foreach (['"/talk/people", "Talk:people"', '"/talk/tickets", "Talk:open"', '"/talk/tickets/{id}/transfer", "Talk:transfer"', '"/talk/tickets/{id}/close", "Talk:close"'] as $route) {
            self::assertStringContainsString($route . ', null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class', $index);
        }
        $openapi = $this->file('docs/api/openapi.yaml');
        foreach (['/talk/people:', '/talk/tickets:', '/talk/tickets/{id}/transfer:', '/talk/tickets/{id}/close:', 'talkIntegration:', 'TalkTicket:'] as $needle) {
            self::assertStringContainsString($needle, $openapi);
        }
        $migration = $this->file('database/migrations/20261115_1100_talk_integration.sql');
        self::assertStringContainsString("'api','talk'", $migration);
        self::assertStringContainsString('uq_support_ticket_external (origin, external_ref)', $migration);
        self::assertStringContainsString('`token_hash` char(64) NOT NULL', $migration);
        self::assertStringNotContainsString('`token` ', $migration, 'O token em si nunca vai para o banco.');
        self::assertFileExists(dirname(__DIR__, 3) . '/database/migrations/20261115_1100_talk_integration.down.sql');
    }
}
