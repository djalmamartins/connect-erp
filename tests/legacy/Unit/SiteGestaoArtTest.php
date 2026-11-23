<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * "Especialistas em gestão condominial" (ADR 0647): só ilustrações próprias da Connect, sem foto de terceiros,
 * no mesmo componente na home e na página de proposta, com texto alternativo e legenda.
 */
final class SiteGestaoArtTest extends TestCase
{
    private const THEME = '/container/web/default/v1';

    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public function testHomeAndProposalUseTheSharedArtComponent(): void
    {
        foreach (['home', 'sales'] as $page) {
            $html = (string)file_get_contents(self::root() . self::THEME . "/pages/{$page}.php");
            self::assertStringContainsString('$this->insert("pages/about-team-art")', $html, $page);
            self::assertDoesNotMatchRegularExpression('/gestao-[abc]\.png/', $html, "{$page}: foto antiga");
        }
    }

    public function testIllustrationsAreOwnSvgWithoutExternalReferences(): void
    {
        $art = (string)file_get_contents(self::root() . self::THEME . '/pages/about-team-art.php');
        preg_match_all('/"file" => "([^"]+)"/', $art, $files);
        preg_match_all('/"alt" => "([^"]+)"/', $art, $alts);
        preg_match_all('/"caption" => "([^"]+)"/', $art, $captions);
        self::assertCount(3, $files[1]);
        self::assertCount(3, $alts[1]);
        self::assertCount(3, $captions[1]);

        foreach ($files[1] as $file) {
            self::assertStringEndsWith('.svg', $file);
            $svg = (string)file_get_contents(self::root() . self::THEME . "/assets/images/gestao/{$file}");
            self::assertNotFalse(simplexml_load_string($svg), "{$file}: SVG válido");
            self::assertStringContainsString('<title', $svg, "{$file}: título acessível");
            self::assertDoesNotMatchRegularExpression('/(href|src)\s*=\s*"(https?:|data:)/i', $svg, "{$file}: sem imagem externa");
            self::assertStringNotContainsString('<image', $svg, "{$file}: sem foto embutida");
            self::assertStringNotContainsString('<text', $svg, "{$file}: sem texto/fonte no desenho");
        }
        foreach (['gestao-a.png', 'gestao-b.png', 'gestao-c.png'] as $old) {
            self::assertFileDoesNotExist(self::root() . self::THEME . "/assets/images/{$old}");
        }
    }
}
