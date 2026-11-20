<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\AppLogger;

/** ADR 0611: o que o teste de carga mostrou que pesava não pode voltar. */
final class PerformanceGuardsTest extends TestCase
{
    private static function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testResidentChargeJoinUsesIndexedSubqueries(): void
    {
        $source = self::file('source/Services/Resident/ResidentPortal.php');
        self::assertDoesNotMatchRegularExpression('/b\.entry_id\s*=\s*e\.id\s+OR\s+b\.id\s*=/', $source, 'OR no JOIN lia todos os boletos para cada linha.');
        self::assertSame(3, substr_count($source, 'LEFT JOIN erp_bank_charges b ON b.id = COALESCE((SELECT d.id FROM erp_bank_charges d WHERE d.entry_id = e.id AND d.active_key = 1 LIMIT 1),'));
    }

    public function testMailQueueLeavesThePageOnlyInProduction(): void
    {
        self::assertFalse(AppLogger::sendsMailInline('production'), 'Na produção o cron envia a fila (ADR 0358).');
        self::assertFalse(AppLogger::sendsMailInline(' Production '));
        self::assertTrue(AppLogger::sendsMailInline('log'));
        self::assertTrue(AppLogger::sendsMailInline('sandbox'));
        self::assertTrue(AppLogger::sendsMailInline(''));
    }

    public function testHtaccessCompressesAndCachesStaticFiles(): void
    {
        $htaccess = self::file('.htaccess');
        self::assertStringContainsString('AddOutputFilterByType DEFLATE text/html', $htaccess);
        self::assertStringContainsString('public, max-age=31536000, immutable" env=CONNECT_VERSIONED', $htaccess);
        self::assertStringContainsString('Header set Cache-Control "public, max-age=86400"', $htaccess);
        // A regra de cache vem antes das regras que bloqueiam pastas, que continuam no arquivo.
        self::assertLessThan(strpos($htaccess, 'RewriteRule ^(?:source|service|tests|vendor)'), strpos($htaccess, 'CONNECT_VERSIONED'));
    }

    public function testSiteHasNoLegacyAnalyticsAndLightIconFont(): void
    {
        foreach (['theme.php', 'sales.php', 'error.php'] as $layout) {
            $html = self::file('container/web/default/v1/layouts/' . $layout);
            self::assertStringNotContainsString('googletagmanager.com', $html, $layout);
            self::assertStringNotContainsString('100..700', $html, $layout . ': Material Symbols só no estilo usado.');
        }
    }
}
