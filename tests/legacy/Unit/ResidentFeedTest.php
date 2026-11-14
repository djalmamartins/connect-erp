<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Resident\ResidentFeed;
use Source\Services\Resident\ResidentLabExamples;
use Source\Services\Resident\ResidentPortal;

/** Ajustes de 14/11 do app do morador (ADR 0302): regras puras do sininho, da busca, do recibo e do PDF de exemplo. */
final class ResidentFeedTest extends TestCase
{
    public function testItemIsNewOnlyAfterTheLastVisitAndFutureDatesStopBeingNew(): void
    {
        $item = ['at' => '2026-11-14 10:00:00'];
        self::assertTrue(ResidentFeed::isNew($item, null, '2026-11-14 12:00:00'), 'Nunca abriu os avisos.');
        self::assertTrue(ResidentFeed::isNew($item, '2026-11-14 09:00:00', '2026-11-14 12:00:00'));
        self::assertFalse(ResidentFeed::isNew($item, '2026-11-14 11:00:00', '2026-11-14 12:00:00'));
        $future = ['at' => '2026-11-29 19:00:00'];
        self::assertTrue(ResidentFeed::isNew($future, '2026-11-14 11:00:00', '2026-11-14 12:00:00'));
        self::assertFalse(ResidentFeed::isNew($future, '2026-11-14 12:30:00', '2026-11-14 12:00:00'), 'Assembleia futura deixa de ser nova depois de vista.');
        self::assertSame(1, ResidentFeed::unseen([$item, $future], '2026-11-14 11:00:00', '2026-11-14 12:00:00'));
    }

    public function testSearchIgnoresAccentsCaseAndNeedsEveryWord(): void
    {
        self::assertSame('caixa dagua limpeza', ResidentFeed::fold('Caixa d’Água — Limpeza!'));
        self::assertTrue(ResidentFeed::matches('agua', 'Limpeza da caixa d’água'));
        self::assertTrue(ResidentFeed::matches('CAIXA limpeza', 'Limpeza da caixa d’água'));
        self::assertFalse(ResidentFeed::matches('caixa piscina', 'Limpeza da caixa d’água'));
        self::assertTrue(ResidentFeed::matches('assembleia', 'Assembleia geral ordinária'));
    }

    public function testReceiptNumberUsesPaymentYearAndEntry(): void
    {
        self::assertSame('2026-000026', ResidentPortal::receiptNumber(26, '2026-10-09'));
        self::assertSame('2025-123456', ResidentPortal::receiptNumber(123456, '2025-01-31'));
    }

    public function testExamplePdfIsAValidOnePageDocument(): void
    {
        $pdf = ResidentLabExamples::pdf(['Regulamento (exemplo)', 'Silêncio das 22h às 7h.']);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringEndsWith("%%EOF\n", $pdf);
        self::assertStringContainsString('/Count 1', $pdf);
        self::assertStringContainsString('\\(exemplo\\)', $pdf, 'Parênteses escapados.');
        self::assertSame('application/pdf', (new \finfo(FILEINFO_MIME_TYPE))->buffer($pdf));
    }
}
