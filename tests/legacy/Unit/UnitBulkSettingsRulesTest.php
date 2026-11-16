<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\Registry\RegistryValidator;
use Source\Services\Erp\UnitBulkSettings;
use Source\Support\Erp\RegistryFormat;

/** Regras puras da etapa 2 da revisão de 15/11 (ADR 0311). */
final class UnitBulkSettingsRulesTest extends TestCase
{
    public function testSettingsUseTheUnitFieldRules(): void
    {
        self::assertSame(['due_day' => 5, 'reads_gas' => 1, 'postage_cents' => 250, 'bill_email_to' => 'ambos', 'partner_agency_id' => null],
            UnitBulkSettings::settings(['due_day' => '5', 'reads_gas' => 'on', 'postage_cents' => 'R$ 2,50', 'bill_email_to' => 'ambos', 'partner_agency_id' => '']));
        self::assertSame(['due_day' => null], UnitBulkSettings::settings(['due_day' => '']), 'Vazio tira o dia padrão.');
        foreach ([[], null, ['label' => 'x'], ['due_day' => '0'], ['bill_address' => 'casa'], ['partner_agency_id' => 'abc']] as $bad) {
            try {
                UnitBulkSettings::settings($bad);
                self::fail('Aceitou ' . json_encode($bad));
            } catch (RegistryValidationException $e) {
                self::assertNotSame([], $e->errors);
            }
        }
        $fields = Definitions::all()['units']['fields'];
        foreach (UnitBulkSettings::SETTINGS as $name) {
            self::assertArrayHasKey($name, $fields, "{$name} existe no cadastro da unidade.");
        }
    }

    public function testCibFormatAndPersonKey(): void
    {
        $field = ['label' => 'CIB', 'type' => 'cib'];
        [$values, $errors] = RegistryValidator::validate(['fields' => ['cib' => $field], 'status' => ['active' => ''], 'requires' => []], ['cib' => ' abc.1234-5 ']);
        self::assertSame([], $errors);
        self::assertSame('ABC12345', $values['cib']);
        [, $errors] = RegistryValidator::validate(['fields' => ['cib' => $field], 'status' => ['active' => ''], 'requires' => []], ['cib' => 'ABC1234']);
        self::assertArrayHasKey('cib', $errors);
        self::assertSame('ABC1234-5', RegistryFormat::display($field, 'ABC12345'));
        self::assertSame(RegistryService::personKey('José  da Silva'), RegistryService::personKey(' jose DA silva'));
        self::assertNotSame(RegistryService::personKey('José da Silva'), RegistryService::personKey('João da Silva'));
    }
}
