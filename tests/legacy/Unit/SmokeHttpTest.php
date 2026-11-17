<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\SmokeHttp;

/** ADR 0356: conferência por HTTP depois de publicar. */
final class SmokeHttpTest extends TestCase
{
    private const OK_HEADERS = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN', 'strict-transport-security' => 'max-age=31536000',
        'referrer-policy' => 'strict-origin-when-cross-origin'];

    /** @return array{name:string, url:string, codes:list<int>, kind:string} */
    private static function check(string $name): array
    {
        foreach (SmokeHttp::checks('connectcondominios.com.br') as $c) {
            if ($c['name'] === $name) {
                return $c;
            }
        }
        self::fail('sem a conferência ' . $name);
    }

    public function testCoversTheFourHostsAndOnlyReads(): void
    {
        $checks = SmokeHttp::checks('Connectcondominios.com.br.');
        $hosts = array_values(array_unique(array_map(static fn (array $c): string => (string)parse_url($c['url'], PHP_URL_HOST), $checks)));
        sort($hosts);
        self::assertSame(['app.connectcondominios.com.br', 'erp.connectcondominios.com.br', 'studio.connectcondominios.com.br', 'www.connectcondominios.com.br'], $hosts);
        self::assertContains('https://erp.connectcondominios.com.br/.git/config', array_column($checks, 'url'));
        self::assertContains('https://erp.connectcondominios.com.br/.env', array_column($checks, 'url'));
        $tool = (string)file_get_contents(dirname(__DIR__, 3) . '/tools/hosting/smoke-http.php');
        self::assertStringNotContainsString('CURLOPT_POST', $tool, 'só GET');
        self::assertStringNotContainsString('vendor/autoload.php', $tool, 'roda sem .env');
    }

    public function testEvaluate(): void
    {
        $health = self::check('API /api/v1/health');
        self::assertNull(SmokeHttp::evaluate($health, 200, self::OK_HEADERS, '{"data":{"status":"ready"}}'));
        self::assertStringContainsString('degraded', (string)SmokeHttp::evaluate($health, 200, self::OK_HEADERS, '{"data":{"status":"degraded"}}'));
        self::assertStringContainsString('503', (string)SmokeHttp::evaluate($health, 503, self::OK_HEADERS, ''));

        $login = self::check('ERP login');
        self::assertNull(SmokeHttp::evaluate($login, 200, self::OK_HEADERS, '<html>'));
        $noHsts = self::OK_HEADERS;
        unset($noHsts['strict-transport-security']);
        self::assertStringContainsString('strict-transport-security', (string)SmokeHttp::evaluate($login, 200, $noHsts, '<html>'));

        $https = self::check('HTTP vai para HTTPS');
        self::assertNull(SmokeHttp::evaluate($https, 301, ['location' => 'https://erp.connectcondominios.com.br/login'], ''));
        self::assertNotNull(SmokeHttp::evaluate($https, 302, ['location' => 'http://erp.connectcondominios.com.br/login'], ''));

        $git = self::check('Bloqueado: /.git/config');
        self::assertNull(SmokeHttp::evaluate($git, 403, [], 'Forbidden'));
        self::assertNotNull(SmokeHttp::evaluate($git, 200, [], "[core]\nbare = false"));
        self::assertSame('mostrou o conteúdo do arquivo', SmokeHttp::evaluate($git, 404, [], "[core]\nbare = false"));
    }

    public function testParseHeadersKeepsTheLastResponse(): void
    {
        $raw = "HTTP/1.1 100 Continue\r\n\r\nHTTP/1.1 200 OK\r\nX-Frame-Options: SAMEORIGIN\r\nLocation: https://x\r\n\r\n";
        self::assertSame(['x-frame-options' => 'SAMEORIGIN', 'location' => 'https://x'], SmokeHttp::parseHeaders($raw));
    }
}
