<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\PdfTime;

/** Prazo do PHP para os PDFs grandes (ADR 0385). */
final class PdfTimeTest extends TestCase
{
    public function testOnlyRaisesTheLimit(): void
    {
        self::assertTrue(PdfTime::needed(30), 'Prazo comum da hospedagem sobe para 120 s.');
        self::assertFalse(PdfTime::needed(0), 'Sem limite (CLI) fica sem limite.');
        self::assertFalse(PdfTime::needed(300), 'Prazo maior já dado (pacote do livro-caixa) fica.');
        self::assertFalse(PdfTime::needed(120));
    }

    public function testEveryLargePdfAsksForTheTime(): void
    {
        $root = dirname(__DIR__, 3) . '/source/Services/Erp/';
        foreach (['ListDocument.php' => 'private static function pdf(', 'CashBookDocument.php' => 'public function pdf(',
            'AccountabilityDocument.php' => 'public function pdf(', 'AccountabilityBookDocument.php' => 'public function pdf('] as $file => $signature) {
            $code = (string)file_get_contents($root . $file);
            $start = strpos($code, $signature);
            self::assertNotFalse($start, $file);
            self::assertStringContainsString('PdfTime::extend();', substr($code, $start, 300), $file);
        }
    }

    public function testExtendKeepsUnlimitedCli(): void
    {
        $before = ini_get('max_execution_time');
        PdfTime::extend();
        self::assertSame($before, ini_get('max_execution_time'));
    }
}
