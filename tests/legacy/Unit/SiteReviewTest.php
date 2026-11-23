<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** Revisão do site antes da produção (23/11/2026). */
final class SiteReviewTest extends TestCase
{
    public function testSearchKeepsAccentsAndDropsSymbols(): void
    {
        self::assertSame('gestão condomínio', str_search('gestão condomínio'));
        self::assertSame('gestão', str_search('gest%C3%A3o'));
        self::assertSame('script', str_search('<script>'));
        self::assertSame('all', str_search('%%%'));
        self::assertSame('all', str_search(null));
    }

    public function testCompetitorNameIsNotInTheSiteAnchors(): void
    {
        $pages = dirname(__DIR__, 3) . '/container/web/default/v1/pages/';
        foreach (['home.php', 'header.php', 'footer.php'] as $file) {
            self::assertStringNotContainsStringIgnoringCase('apcontrole', (string)file_get_contents($pages . $file), $file);
        }
    }

    public function testWhatsappCardShowsTheWhatsappNumber(): void
    {
        $home = (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/default/v1/pages/home.php');
        self::assertStringContainsString("<strong>WhatsApp</strong>\n                        <span><?= htmlspecialchars(CONF_SITE_WHATSAPP ?: CONF_SITE_PHONE) ?>", $home);
    }
}
