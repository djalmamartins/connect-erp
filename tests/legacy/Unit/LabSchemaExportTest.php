<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** ADR 0379: exportar só a estrutura do banco do laboratório para a instalação limpa da produção. */
final class LabSchemaExportTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/schema-' . bin2hex(random_bytes(4));
        mkdir($this->dir . '/tools', 0777, true);
        copy(dirname(__DIR__, 3) . '/tools/lab-exportar-esquema.sh', $this->dir . '/tools/lab-exportar-esquema.sh');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->dir));
    }

    /** @return array{int, string} */
    private function export(string $dump): array
    {
        file_put_contents($this->dir . '/dump.sql', $dump);
        $cmd = 'cd ' . escapeshellarg($this->dir) . ' && DUMP_CMD=' . escapeshellarg('cat ' . $this->dir . '/dump.sql')
            . ' COUNT_CMD=' . escapeshellarg("printf 'users\\t3\\nerp_units\\t\\n'") . ' bash tools/lab-exportar-esquema.sh 2>&1';
        exec($cmd, $out, $code);
        return [$code, implode("\n", $out)];
    }

    public function testWritesStructureWithoutCountersOrDefiners(): void
    {
        [$code, $out] = $this->export("CREATE TABLE `users` (\n  `id` int\n) ENGINE=InnoDB AUTO_INCREMENT=42 DEFAULT CHARSET=utf8mb4;\n"
            . "/*!50013 DEFINER=`root`@`%` SQL SECURITY DEFINER */\nCREATE DEFINER=`root`@`localhost` TRIGGER t BEFORE INSERT ON users FOR EACH ROW SET @x = 1;\n");
        self::assertSame(0, $code, $out);
        $schema = (string)file_get_contents($this->dir . '/database/base/schema.sql');
        self::assertStringContainsString('CREATE TABLE `users`', $schema);
        self::assertStringNotContainsString('AUTO_INCREMENT=42', $schema);
        self::assertStringNotContainsString('DEFINER', $schema);
        self::assertSame("users\t3\nerp_units\t0\n", file_get_contents($this->dir . '/database/base/tables.txt'));
        self::assertStringContainsString('(1 tabelas)', $out);
    }

    public function testRefusesADumpWithData(): void
    {
        [$code, $out] = $this->export("CREATE TABLE `users` (`id` int);\nINSERT INTO `users` VALUES (1);\n");
        self::assertSame(3, $code, $out);
        self::assertFileDoesNotExist($this->dir . '/database/base/schema.sql', 'nada gravado');
    }

    public function testDefaultDumpHasNoData(): void
    {
        self::assertStringContainsString('mariadb-dump --no-data', (string)file_get_contents(dirname(__DIR__, 3) . '/tools/lab-exportar-esquema.sh'));
    }
}
