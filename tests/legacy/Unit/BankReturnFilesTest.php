<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Banking\BankReturns;

/** Lista de arquivos de retorno: forma de cada linha (ADR 0035). */
final class BankReturnFilesTest extends TestCase
{
    public function testPresentFileCountsReconciledDivergentAndRepeated(): void
    {
        $file = BankReturns::presentFile([
            'id' => '3', 'condominium_id' => '1', 'bank_account_id' => '2', 'adapter' => 'sandbox', 'filename' => 'retorno.txt',
            'sha256' => str_repeat('a', 64), 'events_count' => '5', 'applied_count' => '2', 'divergence_count' => '2',
            'total_cents' => '150000', 'created_at' => '2026-11-09 10:00:00',
        ]);
        self::assertSame([5, 2, 2, 1], [$file['events_count'], $file['applied_count'], $file['divergence_count'], $file['other_count']]);
        self::assertSame(['retorno.txt', str_repeat('a', 64), '2026-11-09 10:00:00'], [$file['filename'], $file['sha256'], $file['created_at']]);
        self::assertSame(3, $file['id']);
        self::assertSame(0, BankReturns::presentFile(['events_count' => 1, 'applied_count' => 1, 'divergence_count' => 1] + $file)['other_count'], 'Nunca negativo.');
    }
}
