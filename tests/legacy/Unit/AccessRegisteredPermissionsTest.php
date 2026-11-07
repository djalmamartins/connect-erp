<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PDO;
use PHPUnit\Framework\TestCase;
use Source\Support\Access;

/**
 * Issue #8: permissão não cadastrada em access_permissions nunca cai no fallback por nível.
 * Estes testes não usam MariaDB: um SQLite em memória cobre a consulta de cadastro e uma
 * varredura estática garante que todo slug usado no código tem migration.
 */
final class AccessRegisteredPermissionsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    public function testIsRegisteredOnlyForExistingSlugs(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT UNIQUE)');
        $pdo->exec("INSERT INTO access_permissions (slug) VALUES ('studio.access'), ('site.access')");

        self::assertTrue(Access::isRegistered($pdo, 'studio.access'));
        self::assertTrue(Access::isRegistered($pdo, 'site.access'));
        self::assertFalse(Access::isRegistered($pdo, 'studio.acess'));
        self::assertFalse(Access::isRegistered($pdo, "studio.access' OR '1'='1"));
    }

    public function testEverySlugCheckedInCodeIsRegisteredByAMigration(): void
    {
        $registered = $this->registeredSlugs();
        $missing = array_values(array_diff($this->slugsUsedInCode(), $registered));
        sort($missing);
        self::assertSame([], $missing, 'Slugs usados no código sem migration em access_permissions: ' . implode(', ', $missing));
    }

    /** @return string[] */
    private function slugsUsedInCode(): array
    {
        $slugs = [];
        foreach ($this->phpFiles(['source', 'container']) as $file) {
            $code = (string)file_get_contents($file);
            // can('x.y'), $nav(..., 'x.y') do layout do Studio e 'permission' => 'x.y' dos módulos.
            preg_match_all("/can\\(\\s*['\"]([a-z0-9_]+(?:\\.[a-z0-9_]+)+)['\"]\\s*[,)]/", $code, $m1);
            preg_match_all("/\\\$nav\\([^;]*?,'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'\\)/", $code, $m2);
            preg_match_all("/['\"]permission['\"]\\s*=>\\s*['\"]([a-z0-9_]+(?:\\.[a-z0-9_]+)+)['\"]/", $code, $m3);
            $slugs = array_merge($slugs, $m1[1], $m2[1], $m3[1]);
            // Atalhos de sistemas no layout do Studio: [nome, descrição, url, ícone, 'x.y', atual].
            preg_match_all("/\\['[^']*','[^']*','[^']*','[^']*','([a-z0-9_]+\\.[a-z0-9_.]+)',/", $code, $m4);
            $slugs = array_merge($slugs, $m4[1]);
        }
        $slugs = array_values(array_unique($slugs));
        self::assertContains('site.access', $slugs, 'A varredura deveria encontrar os atalhos do layout do Studio.');
        return $slugs;
    }

    /** @return string[] */
    private function registeredSlugs(): array
    {
        $slugs = [];
        $sqlFiles = array_merge(
            glob(self::ROOT . '/database/migrations/*.sql') ?: [],
            glob(self::ROOT . '/database/legacy-migrations/migrations/*.sql') ?: [],
            glob(self::ROOT . '/container/cms/studio/v1/components/*/database/*.sql') ?: []
        );
        foreach ($sqlFiles as $file) {
            if (str_ends_with($file, '.down.sql')) { continue; }
            $sql = (string)file_get_contents($file);
            if (!preg_match_all('/INSERT[^;]*?INTO\s+`?access_permissions`?[^;]*?VALUES(.*?);/is', $sql, $blocks)) { continue; }
            foreach ($blocks[1] as $values) {
                // Tuplas ('nome','slug', ...): o slug é o segundo literal.
                preg_match_all("/\\(\\s*'[^']*'\\s*,\\s*'([a-z0-9_]+(?:\\.[a-z0-9_]+)+)'/", $values, $m);
                $slugs = array_merge($slugs, $m[1]);
            }
        }
        return array_values(array_unique($slugs));
    }

    /** @return string[] */
    private function phpFiles(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') { $files[] = $file->getPathname(); }
            }
        }
        return $files;
    }
}
