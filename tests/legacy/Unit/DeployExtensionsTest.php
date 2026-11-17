<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADR 0368: extensões do PHP que o código usa sem alternativa estão no composer.json (o composer install da hospedagem
 * para com mensagem clara se faltar uma) e o deploy-check confere as mesmas.
 */
final class DeployExtensionsTest extends TestCase
{
    /** Extensão → um uso no código que quebra sem ela. */
    private const USED = [
        'curl' => 'source/Services/Erp/Banking/Inter/CurlInterHttp.php',
        'dom' => 'source/Support/RichHtml.php',
        'simplexml' => 'source/Services/Erp/Imports/NfeParser.php',
        'gd' => 'source/Services/Inspections/PhotoStorage.php',
        'zip' => 'source/Services/Erp/Imports/SpreadsheetReader.php',
    ];

    public function testComposerRequiresEveryExtensionAndDeployCheckChecksThem(): void
    {
        $root = dirname(__DIR__, 3);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $required = array_map(static fn (string $k): string => substr($k, 4), array_values(array_filter(array_keys($composer['require']), static fn (string $k): bool => str_starts_with($k, 'ext-'))));
        $check = (string)file_get_contents($root . '/service/commands/deploy-check.php');
        preg_match("~foreach \\(\\[([^\\]]+)\\] as \\\$extension\\)~", $check, $m);
        $checked = array_map(static fn (string $e): string => trim($e, " '"), explode(',', $m[1] ?? ''));
        foreach ($required as $extension) {
            self::assertContains($extension, $checked, "ext-{$extension} no composer.json mas fora do deploy-check");
        }
        foreach (array_keys(self::USED) as $extension) {
            self::assertContains($extension, $required, "o código usa {$extension} sem alternativa: declarar ext-{$extension}");
        }
    }

    public function testTheUsesStillExist(): void
    {
        $root = dirname(__DIR__, 3);
        $needle = ['curl' => 'curl_init', 'dom' => 'DOMDocument', 'simplexml' => 'simplexml_load_string', 'gd' => 'imagecreatefromstring', 'zip' => 'ZipArchive'];
        foreach (self::USED as $extension => $file) {
            self::assertStringContainsString($needle[$extension], (string)file_get_contents($root . '/' . $file), $file);
        }
    }

    public function testLockMatchesComposerJson(): void
    {
        $root = dirname(__DIR__, 3);
        $lock = json_decode((string)file_get_contents($root . '/composer.lock'), true, 512, JSON_THROW_ON_ERROR);
        $composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
        $want = array_filter($composer['require'], static fn (string $k): bool => str_starts_with($k, 'ext-') || $k === 'php', ARRAY_FILTER_USE_KEY);
        ksort($want);
        $have = $lock['platform'];
        ksort($have);
        self::assertSame($want, $have, 'rodar composer update --lock');
    }
}
