<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Source\Services\Erp\CollectionReports;

/**
 * Toda planilha exportada pelo sistema passa as células por CollectionReports::csvCell (ADR 0081 e 0158):
 * um nome cadastrado como "=HYPERLINK(...)" não pode virar fórmula no Excel de quem baixa o arquivo.
 */
final class CsvExportSafetyTest extends TestCase
{
    public function testEveryFputcsvInSourceSanitizesItsCells(): void
    {
        $root = dirname(__DIR__, 3) . '/source';
        $unsafe = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $number => $line) {
                if (!str_contains($line, 'fputcsv(')) {
                    continue;
                }
                // Cabeçalho fixo (só literais) e o escritor central (células já saneadas no csvTable) passam.
                $literalHeader = preg_match('/fputcsv\(\$\w+,\s*\[("[^"$]*"\s*,?\s*)+\]/', $line) === 1;
                $central = str_ends_with($file->getPathname(), 'Services/Erp/CollectionReports.php');
                if (!$literalHeader && !$central && !str_contains($line, 'csvCell')) {
                    $unsafe[] = substr($file->getPathname(), strlen($root) + 1) . ':' . ($number + 1);
                }
            }
        }
        self::assertSame([], $unsafe, 'fputcsv sem CollectionReports::csvCell: ' . implode(', ', $unsafe));
    }

    public function testStudioUsersExportNeutralizesFormulaInPublicName(): void
    {
        self::assertSame("'=HYPERLINK(\"http://x\")", CollectionReports::csvCell('=HYPERLINK("http://x")'));
        self::assertSame("'+5511999999999", CollectionReports::csvCell('+5511999999999'));
        self::assertSame('Maria Silva', CollectionReports::csvCell('Maria Silva'));
    }

    public function testStudioUsersExportIsAuditedWithoutPersonalData(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Studio/Studio.php');
        self::assertMatchesRegularExpression('/Audit::record\("export", "users_csv", null, null, \["rows" => count\(\$exportUsers\), "filtered" => [^\]]*\]\)/', $source);
    }
}
