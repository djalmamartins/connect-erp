<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\Imports\ImportRules;
use Source\Services\Erp\ReportExportLimit;
use Source\Support\AuditMask;

/** Endurecimento do ERP (ADR 0087): máscara na auditoria, limite de exportação, contexto das leituras e tetos. */
final class HardeningTest extends TestCase
{
    public function testAuditMaskFieldsByType(): void
    {
        $fields = ['name' => ['type' => 'text'], 'document' => ['type' => 'document'], 'email' => ['type' => 'email'], 'phone' => ['type' => 'phone'], 'notes' => ['type' => 'longtext']];
        self::assertSame(['name' => 'Bia', 'document' => '*********01', 'email' => 'b**@morador.test', 'phone' => '*******4321', 'notes' => 'x'],
            AuditMask::fields(['name' => 'Bia', 'document' => '123.456.789-01', 'email' => 'bia@morador.test', 'phone' => '(11) 98765-4321', 'notes' => 'x'], $fields));
        self::assertSame(['document' => null, 'email' => ''], AuditMask::fields(['document' => null, 'email' => ''], $fields), 'Vazio fica vazio.');
    }

    public function testAuditMaskTextKeepsDatesAndAmounts(): void
    {
        $masked = AuditMask::text('{"cpf":"123.456.789-01","doc":"12345678901","cnpj":"11.222.333/0001-81","email":"bia@morador.test","phone":"(11) 98765-4321",'
            . '"amount_cents":50000,"at":"2026-11-09 10:30:00","competence":"2026-11","id":42}');
        foreach (['123.456.789-01', '12345678901', '11.222.333/0001-81', 'bia@morador.test', '98765-4321'] as $personal) {
            self::assertStringNotContainsString($personal, $masked, "{$personal} mascarado");
        }
        foreach (['"amount_cents":50000', '2026-11-09 10:30:00', '"competence":"2026-11"', '"id":42', 'b**@morador.test'] as $kept) {
            self::assertStringContainsString($kept, $masked, "{$kept} intacto");
        }
    }

    public function testExportLimitAndCaps(): void
    {
        self::assertFalse(ReportExportLimit::exceeded(29));
        self::assertTrue(ReportExportLimit::exceeded(30));
        self::assertSame(30, ReportExportLimit::MAX_PER_HOUR);
        self::assertSame(20000, CollectionReports::MAX_AGREEMENT_ROWS);
        self::assertSame(20000, Agreements::MAX_DELINQUENCY_ROWS);
    }

    public function testReadingContextFromHistory(): void
    {
        $history = [['competence' => '2026-09', 'reading' => '100.000', 'entry_id' => 7], ['competence' => '2026-10', 'reading' => '110.500', 'entry_id' => null],
            ['competence' => '2026-12', 'reading' => '130.000', 'entry_id' => null]];
        self::assertSame(['exists' => true, 'billed' => false, 'later' => true, 'previous' => 100.0], ImportRules::readingContext($history, '2026-10'));
        self::assertSame(['exists' => false, 'billed' => false, 'later' => true, 'previous' => 110.5], ImportRules::readingContext($history, '2026-11'));
        self::assertSame(['exists' => true, 'billed' => true, 'later' => true, 'previous' => null], ImportRules::readingContext($history, '2026-09'));
        self::assertSame(['exists' => false, 'billed' => false, 'later' => false, 'previous' => null], ImportRules::readingContext([], '2026-11'), 'Sem histórico: usa a leitura inicial.');
    }
}
