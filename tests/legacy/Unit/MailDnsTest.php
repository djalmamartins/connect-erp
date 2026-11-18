<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\MailDns;

/** DNS de e-mail do domínio (ADR 0381): SPF, DKIM e DMARC conferidos antes de ligar o envio. */
final class MailDnsTest extends TestCase
{
    /** @param array<string, list<string>> $zone */
    private static function zone(array $zone): callable
    {
        return static fn (string $name): array => $zone[$name] ?? [];
    }

    /** @return array<string, string> */
    private static function statuses(array $result): array
    {
        return array_column($result['items'], 'status', 'name');
    }

    public function testCompleteDomainPasses(): void
    {
        $result = MailDns::check('ConnectCondominios.com.br.', 'hostingermail1', self::zone([
            'connectcondominios.com.br' => ['google-site-verification=x', 'v=spf1 include:_spf.mail.hostinger.com ~all'],
            '_dmarc.connectcondominios.com.br' => ['v=DMARC1; p=quarantine; rua=mailto:dmarc@connectcondominios.com.br'],
            'hostingermail1._domainkey.connectcondominios.com.br' => ['v=DKIM1; k=rsa; p=MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8A'],
        ]));
        self::assertTrue($result['ok']);
        self::assertSame(['SPF' => 'ok', 'DMARC' => 'ok', 'DKIM' => 'ok'], self::statuses($result));
    }

    public function testMissingRecordsAreErrors(): void
    {
        $result = MailDns::check('connectcondominios.com.br', 'sel', self::zone([]));
        self::assertFalse($result['ok']);
        self::assertSame(['SPF' => 'error', 'DMARC' => 'error', 'DKIM' => 'error'], self::statuses($result));
    }

    public function testSpfRules(): void
    {
        $status = static fn (array $txt): string => self::statuses(MailDns::check('a.com.br', null, self::zone(['a.com.br' => $txt])))['SPF'];
        self::assertSame('error', $status(['v=spf1 a ~all', 'v=spf1 mx -all']), 'Dois SPF invalidam os dois.');
        self::assertSame('error', $status(['v=spf1 a +all']), '+all libera qualquer servidor.');
        self::assertSame('error', $status(['v=spf1 all']));
        self::assertSame('warning', $status(['v=spf1 include:x.com']), 'Sem ~all/-all é aviso.');
        self::assertSame('ok', $status(['v=spf1 include:x.com -all']));
        self::assertSame('error', $status(['v=spf10 -all']), 'Prefixo parecido não conta.');
    }

    public function testDmarcAndDkimRules(): void
    {
        $dmarc = static fn (array $txt): string => self::statuses(MailDns::check('a.com.br', null, self::zone(['_dmarc.a.com.br' => $txt])))['DMARC'];
        self::assertSame('warning', $dmarc(['v=DMARC1; p=none']), 'p=none é o começo, vale como aviso.');
        self::assertSame('ok', $dmarc(['v=DMARC1;p=reject']));
        self::assertSame('error', $dmarc(['v=DMARC1; rua=mailto:x@a.com.br']), 'Sem p= não vale.');
        self::assertSame('error', $dmarc(['v=DMARC1; p=quarantine', 'v=DMARC1; p=none']));

        $dkim = static fn (array $txt): string => self::statuses(MailDns::check('a.com.br', 's1', self::zone(['s1._domainkey.a.com.br' => $txt])))['DKIM'];
        self::assertSame('error', $dkim(['v=DKIM1; k=rsa; p=']), 'p= vazio é chave revogada.');
        self::assertSame('ok', $dkim(['v=DKIM1; p=ABC+/=']));
    }

    public function testWithoutSelectorDkimIsOnlyAWarning(): void
    {
        $result = MailDns::check('a.com.br', null, self::zone([
            'a.com.br' => ['v=spf1 mx -all'], '_dmarc.a.com.br' => ['v=DMARC1; p=reject'],
        ]));
        self::assertTrue($result['ok']);
        self::assertSame('warning', self::statuses($result)['DKIM']);
    }

    public function testRejectsInvalidDomainAndSelectorWithoutLookup(): void
    {
        $asked = [];
        $spy = static function (string $name) use (&$asked): array {
            $asked[] = $name;
            return [];
        };
        self::assertFalse(MailDns::check('a.com.br/../x', null, $spy)['ok']);
        self::assertSame([], $asked, 'Domínio inválido não consulta o DNS.');
        $result = MailDns::check('a.com.br', 'x y', $spy);
        self::assertSame('error', self::statuses($result)['DKIM']);
        self::assertNotContains('x y._domainkey.a.com.br', $asked);
    }

    public function testDnsFailureIsReportedApartFromMissingRecord(): void
    {
        $result = MailDns::check('a.com.br', 's1', static fn (string $name): ?array => null);
        self::assertFalse($result['ok']);
        foreach ($result['items'] as $item) {
            self::assertSame('error', $item['status']);
            self::assertStringContainsString('consultar o DNS', $item['message']);
        }
    }

    public function testDeployWarningsOnlyWhenDeliveryIsOnAndNeverErrors(): void
    {
        $asked = [];
        $spy = static function (string $name) use (&$asked): array {
            $asked[] = $name;
            return [];
        };
        self::assertSame([], MailDns::deployWarnings(['ERP_MAIL_DELIVERY' => 'log', 'MOVESOS_SMTP_FROM_ADDRESS' => 'a@b.com.br'], $spy));
        self::assertSame([], $asked, 'Envio desligado não consulta o DNS.');
        self::assertStringContainsString('MOVESOS_SMTP_FROM_ADDRESS', MailDns::deployWarnings(['ERP_MAIL_DELIVERY' => 'production',
            'MOVESOS_SMTP_FROM_ADDRESS' => '<avisos@connectcondominios.com.br>'], $spy)[0], 'Marcador do modelo não é e-mail.');

        $env = ['ERP_MAIL_DELIVERY' => 'production', 'MOVESOS_SMTP_FROM_ADDRESS' => 'avisos@connectcondominios.com.br', 'MAIL_DKIM_SELECTOR' => 'h1'];
        $warnings = MailDns::deployWarnings($env, $spy);
        self::assertCount(3, $warnings);
        self::assertStringStartsWith('E-mail (SPF):', $warnings[0]);
        self::assertContains('h1._domainkey.connectcondominios.com.br', $asked, 'O seletor vem do .env.');

        $complete = self::zone([
            'connectcondominios.com.br' => ['v=spf1 include:_spf.mail.hostinger.com ~all'],
            '_dmarc.connectcondominios.com.br' => ['v=DMARC1; p=quarantine'],
            'h1._domainkey.connectcondominios.com.br' => ['v=DKIM1; p=MIIB'],
        ]);
        self::assertSame([], MailDns::deployWarnings($env, $complete));
    }

    public function testDeployCheckUsesItAsWarning(): void
    {
        $script = (string)file_get_contents(dirname(__DIR__, 3) . '/service/commands/deploy-check.php');
        self::assertStringContainsString("\$ready['warnings'] = array_merge(\$ready['warnings'], \\Source\\Support\\MailDns::deployWarnings(", $script);
    }
}
