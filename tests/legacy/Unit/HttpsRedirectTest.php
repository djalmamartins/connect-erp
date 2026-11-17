<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\HttpsRedirect;

/** ADR 0364: em produção, http:// vai para https:// no mesmo host do domínio, antes da sessão. */
final class HttpsRedirectTest extends TestCase
{
    private const ENV = ['APP_ENV' => 'production', 'APP_URL' => 'https://www.connectcondominios.com.br', 'CONNECT_FORCE_HTTPS' => false];
    private const SERVER = ['HTTP_HOST' => 'erp.connectcondominios.com.br', 'REQUEST_URI' => '/login?x=1', 'REQUEST_METHOD' => 'GET'];

    public function testProductionHttpGoesToHttpsOnTheSameHostAndPath(): void
    {
        self::assertSame(['url' => 'https://erp.connectcondominios.com.br/login?x=1', 'code' => 301], HttpsRedirect::target(self::SERVER, self::ENV));
        self::assertSame(301, HttpsRedirect::target(['REQUEST_METHOD' => 'HEAD'] + self::SERVER, self::ENV)['code'] ?? null);
        self::assertSame(308, HttpsRedirect::target(['REQUEST_METHOD' => 'POST'] + self::SERVER, self::ENV)['code'] ?? null, 'POST mantém o método');
        self::assertSame('https://connectcondominios.com.br/', HttpsRedirect::target(['HTTP_HOST' => 'connectcondominios.com.br', 'REQUEST_URI' => '/'] + self::SERVER, self::ENV)['url'] ?? null);
        self::assertSame('https://app.connectcondominios.com.br/', HttpsRedirect::target(['HTTP_HOST' => 'APP.connectcondominios.com.br:80', 'REQUEST_URI' => '/'] + self::SERVER, self::ENV)['url'] ?? null);
    }

    public function testNothingWhenAlreadyHttpsOutsideProductionOrSwitchedOff(): void
    {
        foreach ([['HTTPS' => 'on'], ['HTTP_X_FORWARDED_PROTO' => 'https, http'], ['REQUEST_SCHEME' => 'https'], ['SERVER_PORT' => '443']] as $https) {
            self::assertNull(HttpsRedirect::target($https + self::SERVER, self::ENV), json_encode($https));
        }
        self::assertNotNull(HttpsRedirect::target(['HTTPS' => 'off'] + self::SERVER, self::ENV));
        self::assertNull(HttpsRedirect::target(self::SERVER, ['APP_ENV' => 'local'] + self::ENV));
        self::assertNull(HttpsRedirect::target(self::SERVER, ['APP_ENV' => false] + self::ENV));
        self::assertNull(HttpsRedirect::target(self::SERVER, ['CONNECT_FORCE_HTTPS' => 'false'] + self::ENV));
        self::assertNotNull(HttpsRedirect::target(self::SERVER, ['CONNECT_FORCE_HTTPS' => 'true'] + self::ENV));
    }

    public function testNeverRedirectsToAHostOutsideTheDomain(): void
    {
        foreach (['evil.example', 'connectcondominios.com.br.evil.example', 'evilconnectcondominios.com.br', 'erp.connectcondominios.com.br@evil', ''] as $host) {
            self::assertNull(HttpsRedirect::target(['HTTP_HOST' => $host] + self::SERVER, self::ENV), $host);
        }
        self::assertNull(HttpsRedirect::target(self::SERVER, ['APP_URL' => ''] + self::ENV), 'sem APP_URL não sabe o domínio');
        self::assertSame('https://erp.connectcondominios.com.br/', HttpsRedirect::target(['REQUEST_URI' => '//evil.example/x'] + self::SERVER, self::ENV)['url'] ?? null);
        self::assertSame('https://erp.connectcondominios.com.br/', HttpsRedirect::target(['REQUEST_URI' => "/x\r\nSet-Cookie: a=b"] + self::SERVER, self::ENV)['url'] ?? null);
    }

    public function testDomainFromAppUrl(): void
    {
        self::assertSame('connectcondominios.com.br', HttpsRedirect::domain('https://www.connectcondominios.com.br'));
        self::assertSame('connectcondominios.com.br', HttpsRedirect::domain('https://erp.connectcondominios.com.br/'));
        self::assertSame('connectcondominios.com.br', HttpsRedirect::domain('https://connectcondominios.com.br'));
        self::assertSame('portal.exemplo.com.br', HttpsRedirect::domain('https://portal.exemplo.com.br'));
        self::assertSame('', HttpsRedirect::domain(''));
    }

    public function testFrontControllerCallsItBeforeTheSession(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        $call = strpos($index, '\\Source\\Support\\HttpsRedirect::handle();');
        self::assertNotFalse($call);
        self::assertLessThan(strpos($index, '$session = new Session();'), $call);
    }
}
