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
        self::assertSame("frame-ancestors 'self'", $prod['Content-Security-Policy']);
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
        self::assertLessThan(strpos($index, 'talk_host()'), $send, 'antes do Talk e do roteador');
        $support = (string)file_get_contents($root . '/source/Controllers/Web/Support/Support.php');
        self::assertStringContainsString('frame-ancestors *', $support);
        self::assertStringContainsString("header_remove('X-Frame-Options')", $support, 'o widget do Help Desk abre dentro de outros sites');
    }
}
