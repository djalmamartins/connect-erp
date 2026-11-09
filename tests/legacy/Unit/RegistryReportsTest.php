<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\RegistryReports;

/** Relatórios cadastrais e administrativos (ADR 0084): máscaras e CSV seguro. */
final class RegistryReportsTest extends TestCase
{
    public function testMasks(): void
    {
        self::assertSame('*******4321', RegistryReports::maskPhone('(11) 98765-4321'));
        self::assertSame('', RegistryReports::maskPhone(null));
        self::assertSame('b**@morador.test', RegistryReports::maskEmail('bia@morador.test'));
        self::assertSame('', RegistryReports::maskEmail('não é e-mail'));
    }

    public function testPeopleCsvKeepsMaskedDataAndBlocksFormulas(): void
    {
        $table = RegistryReports::csvTable('people', ['rows' => [[
            'condominium_name' => 'Alpha', 'unit_label' => '101', 'relationship_label' => 'Proprietário', 'name' => '=HYPERLINK("http://x")',
            'document' => '*********01', 'email' => 'b**@morador.test', 'phone' => '*******4321', 'billing_by_email' => true, 'notices' => false,
        ]]]);
        self::assertSame(['Condomínio', 'Unidade', 'Vínculo', 'Nome', 'Documento', 'E-mail', 'Telefone', 'Cobrança por e-mail', 'Comunicados'], $table['header']);
        self::assertSame(['Alpha', '101', 'Proprietário', "'=HYPERLINK(\"http://x\")", '*********01', 'b**@morador.test', '*******4321', 'sim', 'não'], $table['rows'][0]);
    }

    public function testEveryReportHasCsv(): void
    {
        foreach (['reservations', 'units', 'people', 'condominiums', 'occurrences', 'pending-payments', 'closings', 'moves', 'addresses', 'logs', 'access-people', 'sent-boletos'] as $kind) {
            self::assertNotEmpty(RegistryReports::csvTable($kind, ['rows' => []])['header'], $kind);
        }
        $logs = RegistryReports::csvTable('logs', ['rows' => [['created_at' => '2026-11-09 10:30:00', 'user_name' => 'Ana', 'action' => 'update', 'entity' => 'erp_entries',
            'entity_id' => 7, 'severity' => 'info', 'ip' => '10.0.0.1', 'context' => '@SUM(1)']]]);
        self::assertSame('09/11/2026 10:30', $logs['rows'][0][0]);
        self::assertSame("'@SUM(1)", $logs['rows'][0][7]);
        $this->expectException(InvalidArgumentException::class);
        RegistryReports::csvTable('outro', []);
    }
}
