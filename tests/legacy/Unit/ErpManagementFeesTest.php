<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ErpSettings;
use Source\Services\Erp\ManagementFees;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0402: regras puras dos honorários (validação, competência e período do contrato) e a chave das notas desligada. */
final class ErpManagementFeesTest extends TestCase
{
    public function testValidatesAndNormalizes(): void
    {
        $data = ManagementFees::validate(['monthly_fee' => '1.500,50', 'start_date' => '2026-01-10', 'due_day' => '5', 'service_description' => '  ', 'notes' => '']);
        self::assertSame(['monthly_fee_cents' => 150050, 'start_date' => '2026-01-10', 'end_date' => null, 'due_day' => 5,
            'service_description' => ManagementFees::DEFAULT_SERVICE, 'status' => 'active', 'notes' => null], $data);
    }

    public function testRejectsBadInput(): void
    {
        try {
            ManagementFees::validate(['monthly_fee' => '0', 'start_date' => '2026-02-30', 'end_date' => 'x', 'due_day' => 29, 'status' => 'paid']);
            self::fail('Entrada inválida passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame(['monthly_fee', 'start_date', 'end_date', 'due_day', 'status'], array_keys($exception->errors));
        }
        try {
            ManagementFees::validate(['monthly_fee' => '100,00', 'start_date' => '2026-05-01', 'end_date' => '2026-04-30', 'due_day' => 1]);
            self::fail('Fim antes do início passou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame(['end_date'], array_keys($exception->errors));
        }
    }

    public function testBillableCompetence(): void
    {
        $contract = ['start_date' => '2026-03-15', 'end_date' => '2026-06-01'];
        self::assertSame([false, true, true, true, false], array_map(static fn (string $c): bool => ManagementFees::billable($contract, $c),
            ['2026-02', '2026-03', '2026-05', '2026-06', '2026-07']));
        self::assertTrue(ManagementFees::billable(['start_date' => '2026-01-01', 'end_date' => null], '2030-12'));
    }

    public function testCompetenceFormat(): void
    {
        self::assertSame('2026-11', ManagementFees::competence('2026-11'));
        foreach (['2026-13', '2026-1', '1999-12', null, '2026-11-01'] as $bad) {
            try {
                ManagementFees::competence($bad);
                self::fail('Competência inválida passou: ' . var_export($bad, true));
            } catch (RegistryValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testNfseSwitchIsOffByDefaultAndGlobal(): void
    {
        $spec = ErpSettings::CATALOG['nfse.enabled'];
        self::assertSame([false, 'global', 'bool'], [$spec['default'], $spec['scope'], $spec['type']]);
        self::assertTrue(ErpSettings::safeKey('nfse.enabled'));
    }
}
