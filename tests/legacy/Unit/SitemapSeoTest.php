<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\Seo;
use Source\Support\Sitemap;

/** ADR 0399: sitemap.xml e dados da empresa (JSON-LD) no site. */
final class SitemapSeoTest extends TestCase
{
    public function testSitemapEscapesAndDeduplicatesAndFormatsDates(): void
    {
        $xml = (new Sitemap())
            ->add('https://www.exemplo.test/artigos/a&b', '2026-11-20 10:30:00')
            ->add('https://www.exemplo.test/artigos/a&b', '2026-11-21 10:30:00')
            ->add('https://www.exemplo.test/')
            ->render();

        self::assertSame(1, substr_count($xml, 'a&amp;b'));
        self::assertStringContainsString('<lastmod>2026-11-20</lastmod>', $xml);
        self::assertStringContainsString('<loc>https://www.exemplo.test/</loc></url>', $xml);
        $doc = new \DOMDocument();
        self::assertTrue($doc->loadXML($xml), 'o sitemap precisa ser XML válido');
        self::assertSame('http://www.sitemaps.org/schemas/sitemap/0.9', $doc->documentElement->namespaceURI);
    }

    public function testRouteAndRobotsPointToSitemap(): void
    {
        $root = dirname(__DIR__, 3);
        self::assertStringContainsString('$route->get("/sitemap.xml", "Web:sitemap");', (string)file_get_contents($root . '/container/web/default/v1/routes.php'));
        self::assertMatchesRegularExpression('~^Sitemap: https://www\.connectcondominios\.com\.br/sitemap\.xml$~m', (string)file_get_contents($root . '/robots.txt'));
        self::assertTrue(method_exists(\Source\Controllers\Web\Connect\Web::class, 'sitemap'));
    }

    public function testBusinessAddsStructuredDataFromSettings(): void
    {
        $head = (new Seo())->business('https://www.exemplo.test/logo.png')
            ->render('Título', 'Descrição', 'https://www.exemplo.test/', 'https://www.exemplo.test/share.jpg');

        self::assertMatchesRegularExpression('~<script type="application/ld\+json">\{.*"@type":"(LocalBusiness|Organization)".*\}</script>~', $head);
        self::assertStringContainsString('https://www.exemplo.test/logo.png', $head);
        self::assertStringNotContainsString('<script>', $head);
    }
}
