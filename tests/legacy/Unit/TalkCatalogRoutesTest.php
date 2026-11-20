<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Services\Talk\TalkCatalog;

/** Dados dos condomínios para o Talk (ADR 0624): paginação, rotas, OpenAPI e nada de pessoa nas consultas. */
final class TalkCatalogRoutesTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testPageBecomesSafeLimitOffset(): void
    {
        self::assertSame([1, 0], TalkCatalog::page(1, 50));
        self::assertSame([3, 100], TalkCatalog::page('3', 50));
        foreach ([0, -2, 'abc', null, '1 OR 1=1', [], 10001] as $bad) {
            self::assertSame([1, 0], TalkCatalog::page($bad, 50), 'Página inválida volta para a 1.');
        }
        self::assertSame([2, 200], TalkCatalog::page(2, TalkCatalog::UNITS_PAGE_SIZE));
    }

    public function testRoutesAndOpenApiAreWired(): void
    {
        $index = $this->file('index.php');
        foreach (['/talk/condominiums", "Talk:condominiums"', '/talk/condominiums/{id}", "Talk:condominium"', '/talk/condominiums/{id}/units", "Talk:units"', '/talk/condominiums/{id}/announcements", "Talk:announcements"'] as $route) {
            self::assertStringContainsString('$route->get("' . $route . ', null, \\Source\\Support\\Middleware\\TalkApiAuthenticated::class);', $index);
        }
        $openapi = $this->file('docs/api/openapi.yaml');
        foreach (['/talk/condominiums:', '/talk/condominiums/{id}:', '/talk/condominiums/{id}/units:', '/talk/condominiums/{id}/announcements:', 'TalkPageMeta:'] as $needle) {
            self::assertStringContainsString($needle, $openapi);
        }
        self::assertSame(4, substr_count($this->file('source/Controllers/Api/V1/Talk.php'), "agent('condominiums.read')"));
    }

    public function testCatalogNeverSelectsPeopleOrBankData(): void
    {
        $code = $this->file('source/Services/Talk/TalkCatalog.php');
        preg_match_all('/SELECT\s(.+?)\sFROM\s+(\w+)/s', $code, $selects, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(6, count($selects));
        foreach ($selects as [, $columns, $table]) {
            self::assertContains($table, ['condominiums', 'erp_common_areas', 'erp_units', 'erp_announcements'], 'Só tabelas do condomínio, nunca de pessoa.');
            foreach (['cnpj', 'resident', 'syndic', 'bank', 'email', 'phone', 'document', 'owner'] as $forbidden) {
                self::assertStringNotContainsStringIgnoringCase($forbidden, $columns, "A consulta não pode trazer {$forbidden}.");
            }
        }
        self::assertStringNotContainsString('SELECT *', $code);
    }
}
