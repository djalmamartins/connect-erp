<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\SecurityHeaders;

/** ADR 0355: cabeçalhos de segurança em toda resposta. */
final class SecurityHeadersTest extends TestCase
{
    public function testBaselineAndHstsOnlyOutsideTheLab(): void
    {
        $prod = SecurityHeaders::headers(false);
        self::assertSame('nosniff', $prod['X-Content-Type-Options']);
        self::assertSame('SAMEORIGIN', $prod['X-Frame-Options']);
        self::assertSame("frame-ancestors 'self'; object-src 'none'; base-uri 'self'", $prod['Content-Security-Policy'], 'sem host, sem form-action');
        self::assertSame('strict-origin-when-cross-origin', $prod['Referrer-Policy']);
        self::assertStringContainsString('camera=(self)', $prod['Permissions-Policy'], 'a vistoria usa a câmera');
        self::assertSame('max-age=31536000', $prod['Strict-Transport-Security']);
        self::assertStringNotContainsString('includeSubDomains', $prod['Strict-Transport-Security']);
        self::assertArrayNotHasKey('Strict-Transport-Security', SecurityHeaders::headers(true), 'lab sem HSTS');
    }

    public function testSentAtTheTopOfTheFrontControllerAndWidgetStaysEmbeddable(): void
    {
        $root = dirname(__DIR__, 3);
        $index = (string)file_get_contents($root . '/index.php');
        $send = strpos($index, 'SecurityHeaders::send(');
        self::assertNotFalse($send);
        self::assertLessThan(strpos($index, 'new Router('), $send, 'antes do roteador');
        $support = (string)file_get_contents($root . '/source/Controllers/Web/Support/Support.php');
        self::assertStringContainsString('frame-ancestors *', $support);
        self::assertStringContainsString("header_remove('X-Frame-Options')", $support, 'o widget do Help Desk abre dentro de outros sites');
    }

    public function testPrivateHostsAndPathsAreNotIndexed(): void
    {
        foreach ([['erp.connectcondominios.com.br', '/'], ['APP.connectcondominios.com.br', '/inicio'], ['studio.connect.lab', '/studio'],
            ['talk.connectcondominios.com.br', '/'], ['www.connectcondominios.com.br', '/api/v1/health'], ['www.connect.lab', '/app'],
            ['www.connect.lab', '/app/boletos'], ['connectcondominios.com.br', '/ops/404']] as [$host, $path]) {
            self::assertSame('noindex, nofollow', SecurityHeaders::headers(false, $host, $path)['X-Robots-Tag'] ?? null, $host . $path);
        }
        foreach ([['www.connectcondominios.com.br', '/'], ['connectcondominios.com.br', '/suporte/artigo/boletos'],
            ['www.connect.lab', '/apple'], ['www.connect.lab', '/blog/application']] as [$host, $path]) {
            self::assertArrayNotHasKey('X-Robots-Tag', SecurityHeaders::headers(false, $host, $path), $host . $path);
        }
    }

    public function testRobotsTxtKeepsCrawlersOutOfPrivatePaths(): void
    {
        $robots = (string)file_get_contents(dirname(__DIR__, 3) . '/robots.txt');
        foreach (['User-agent: *', 'Disallow: /api/', 'Disallow: /app/', 'Disallow: /ops/'] as $line) {
            self::assertStringContainsString($line, $robots);
        }
        self::assertDoesNotMatchRegularExpression('~^Disallow: /\s*$~m', $robots, 'o site e a Central de Ajuda continuam indexados');
    }

    public function testCspLimitsPluginsBaseAndFormsToOurDomainWithoutTouchingScripts(): void
    {
        $csp = SecurityHeaders::headers(false, 'erp.connectcondominios.com.br', '/')['Content-Security-Policy'];
        self::assertSame("frame-ancestors 'self'; object-src 'none'; base-uri 'self'; form-action 'self' connectcondominios.com.br *.connectcondominios.com.br", $csp);
        self::assertStringNotContainsString('script-src', $csp, 'scripts ficam para depois do piloto (ADR 0387)');
        self::assertStringNotContainsString('default-src', $csp);
        foreach (['www.connectcondominios.com.br' => 'connectcondominios.com.br', 'connectcondominios.com.br' => 'connectcondominios.com.br',
            'APP.Connect.Lab:8443' => 'connect.lab', 'studio.connect.lab' => 'connect.lab', 'connect.lab' => 'connect.lab',
            'outro.connectcondominios.com.br' => 'outro.connectcondominios.com.br', '' => '', 'x"; script-src *' => '', 'localhost' => ''] as $host => $base) {
            self::assertSame($base, SecurityHeaders::baseDomain($host), $host);
        }
        self::assertStringNotContainsString('form-action', SecurityHeaders::csp('x"; script-src *'), 'host estranho não entra no cabeçalho');
        // Conferido no Chromium: origem sem porta só vale na porta padrão; o lab numa porta própria precisa dela.
        self::assertStringEndsWith("form-action 'self' connect.lab:8443 *.connect.lab:8443", SecurityHeaders::csp('erp.connect.lab:8443'));
    }
}
