<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\UnitDocuments;

/** Documentos da unidade: código de verificação e separação vencidas × a vencer (ADR 0044). */
final class UnitDocumentsTest extends TestCase
{
    public function testVerificationCodeIsUnambiguousAndNormalized(): void
    {
        $codes = [];
        for ($i = 0; $i < 200; $i++) {
            $code = UnitDocuments::newCode();
            self::assertMatchesRegularExpression('/^[A-HJ-NP-Z2-9]{16}$/', $code, 'Sem 0, 1, I, L ou O.');
            $codes[$code] = true;
        }
        self::assertCount(200, $codes, 'Códigos aleatórios não se repetem na amostra.');
        self::assertSame('ABCDEFGHJKMNPQRS', UnitDocuments::normalizeCode(' abcd-efgh jkmn-pqrs '));
        foreach (['', 'ABCD-EFGH', 'ABCDEFGHJKMNPQR0', 'ABCDEFGHJKMNPQRSX', "ABCD'; DROP"] as $bad) {
            self::assertNull(UnitDocuments::normalizeCode($bad), $bad);
        }
    }

    public function testSplitOverdueAndUpcoming(): void
    {
        $parts = UnitDocuments::split([
            ['due_on' => '2026-10-10', 'amount_cents' => '50000'],
            ['due_on' => '2026-11-10', 'amount_cents' => 50000],
            ['due_on' => '2026-12-10', 'amount_cents' => 30000],
        ], '2026-11-10');
        self::assertCount(1, $parts['overdue'], 'Vence hoje ainda não está vencida.');
        self::assertSame([50000, 80000], [$parts['overdue_cents'], $parts['upcoming_cents']]);
        self::assertSame([], UnitDocuments::split([], '2026-11-10')['overdue']);
    }

    public function testKindsCoverTheCatalogDocuments(): void
    {
        self::assertSame(['debt_certificate', 'delinquency_declaration', 'quittance', 'consent_letter'], array_keys(UnitDocuments::KINDS));
    }
}
