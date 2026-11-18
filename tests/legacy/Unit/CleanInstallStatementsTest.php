<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\CleanInstall;

/** ADR 0396: os arquivos .sql da instalação limpa são separados em comandos sem quebrar texto com ";". */
final class CleanInstallStatementsTest extends TestCase
{
    public function testSplitsOnSemicolonsOutsideQuotesAndComments(): void
    {
        $sql = "-- comentário; com ponto e vírgula\nCREATE TABLE `a;b` (`id` int);\n"
            . "INSERT INTO t VALUES ('texto; com ponto e vírgula', 'it\\'s; ok', \"aspas; duplas\");\n"
            . "/* bloco; ignorado */\n/*!40101 SET NAMES utf8mb4 */;\n# outro; comentário\nUPDATE t SET x = 1";
        self::assertSame([
            "CREATE TABLE `a;b` (`id` int)",
            "INSERT INTO t VALUES ('texto; com ponto e vírgula', 'it\\'s; ok', \"aspas; duplas\")",
            '/*!40101 SET NAMES utf8mb4 */',
            'UPDATE t SET x = 1',
        ], CleanInstall::statements($sql));
    }

    public function testRealFilesSplitIntoStatements(): void
    {
        $root = dirname(__DIR__, 3);
        $schema = CleanInstall::statements((string)file_get_contents($root . '/database/base/schema.sql'));
        $creates = array_filter($schema, static fn(string $s): bool => str_starts_with($s, 'CREATE TABLE'));
        self::assertSame(substr_count((string)file_get_contents($root . '/database/base/schema.sql'), "\nCREATE TABLE "), count($creates));
        self::assertSame([], array_filter($schema, static fn(string $s): bool => stripos($s, 'INSERT INTO') === 0), 'a estrutura base não traz dados');
        foreach (glob($root . '/database/legacy-migrations/migrations/*.sql') ?: [] as $file) {
            self::assertNotSame([], CleanInstall::statements((string)file_get_contents($file)), basename($file));
        }
    }
}
