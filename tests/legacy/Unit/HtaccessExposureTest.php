<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Arquivos internos fora do alcance do navegador (ADR 0338). Lê as regras [F] do .htaccess da raiz e confere, com o mesmo
 * padrão das regras, que caminhos internos (.git, backups locais, ferramentas, docs, migrations, código dos temas) são
 * barrados e que os assets, o favicon do Talk e o .well-known do SSL continuam públicos. Conferido também num Apache real
 * em 17/11/2026 (403 nos internos, 200 nos assets).
 */
final class HtaccessExposureTest extends TestCase
{
    private const BLOCKED = ['.git/config', '.local/backups/connect-erp-db-20261116.sql.gz', '.github/workflows/quality.yml', '.claude/rules/security.md',
        '.env', 'tools/hosting/backup.sh', 'tools/help.php', 'docs/ROADMAP_V1.md', 'database/migrations/x.sql', 'apps/morador/package.json',
        'source/Boot/Config.php', 'vendor/autoload.php', 'storage/logs/movesos-2026-11-16.log', 'storage/private/inspections/1.jpg',
        'storage/files/2026/11/ticket-12-ab12cd34.pdf', 'storage/images/2026/11/public-ticket-7-ab12cd34.jpg',
        'container/erp/connect/v1/routes.php', 'container/web/support/v1/kb/categorias.json', 'container/web/support/v1/kb/artigos/a.md',
        'container/cms/studio/v1/components/proposals/database/001_create_proposals.sql', 'Container/erp/connect/v1/routes.PHP'];

    private const PUBLIC = ['.well-known/acme-challenge/abc', 'container/erp/connect/v1/assets/erp.min.css', 'container/talk/connect/v1/favicon.ico',
        'container/talk/connect/v1/manifest.webmanifest', 'container/web/default/v1/assets/images/manifest.json',
        'container/shared/assets/organic/organic.min.css', 'storage/images/cache/foto.jpg', 'storage/files/2026/11/boleto-modelo.pdf', 'erp/condominios', 'api/v1/health', 'index.php'];

    /** @return list<array{pattern:string, nocase:bool, cond:?string}> */
    private static function forbidRules(): array
    {
        $lines = file(dirname(__DIR__, 3) . '/.htaccess', FILE_IGNORE_NEW_LINES) ?: [];
        $rules = [];
        $cond = null;
        foreach ($lines as $line) {
            $line = trim($line);
            if (preg_match('/^RewriteCond %\{REQUEST_URI\} !(\S+)$/', $line, $m)) {
                $cond = $m[1];
                continue;
            }
            if (preg_match('/^RewriteRule (\S+) - \[([^\]]*\bF\b[^\]]*)\]$/', $line, $m)) {
                $rules[] = ['pattern' => $m[1], 'nocase' => str_contains($m[2], 'NC'), 'cond' => $cond];
            }
            $cond = null;
        }
        return $rules;
    }

    private static function denied(string $path, array $rules, string $filesMatch): bool
    {
        foreach ($rules as $rule) {
            if ($rule['cond'] !== null && preg_match('~' . $rule['cond'] . '~', '/' . $path)) {
                continue;
            }
            if (preg_match('~' . $rule['pattern'] . '~' . ($rule['nocase'] ? 'i' : ''), $path)) {
                return true;
            }
        }
        return preg_match('~' . $filesMatch . '~', basename($path)) === 1;
    }

    public function testInternalPathsAreForbiddenAndAssetsStayPublic(): void
    {
        $htaccess = (string)file_get_contents(dirname(__DIR__, 3) . '/.htaccess');
        self::assertSame(1, preg_match('/<FilesMatch "(\\\\\.\(\?:sql\|gz[^"]+)">/', $htaccess, $m), 'FilesMatch dos arquivos de dados sumiu.');
        $rules = self::forbidRules();
        self::assertGreaterThanOrEqual(5, count($rules));
        foreach (self::BLOCKED as $path) {
            self::assertTrue(self::denied($path, $rules, $m[1]), "Deveria estar bloqueado: {$path}");
        }
        foreach (self::PUBLIC as $path) {
            self::assertFalse(self::denied($path, $rules, $m[1]), "Deveria continuar público: {$path}");
        }
    }
}
