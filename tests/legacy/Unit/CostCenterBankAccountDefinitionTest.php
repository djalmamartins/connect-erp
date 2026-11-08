<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Centros de custo (CN-002) e contas bancárias (CN-003), sem banco (ADR 0021). */
final class CostCenterBankAccountDefinitionTest extends TestCase
{
    public function testDefinitionsMatchCatalogPages(): void
    {
        $centers = Definitions::get('cost_centers');
        $accounts = Definitions::get('bank_accounts');
        self::assertSame(['CN-002', 'financeiro', 'optional'], [$centers['page'], $centers['group'], $centers['scope']]);
        self::assertSame(['CN-003', 'financeiro', 'required'], [$accounts['page'], $accounts['group'], $accounts['scope']]);
        self::assertSame('cost_centers', $accounts['fields']['cost_center_id']['ref']);
        self::assertSame([['field' => 'is_main', 'value' => 1, 'per' => 'condominium_id', 'message' => $accounts['single_active'][0]['message']]], $accounts['single_active']);
        foreach (array_keys($accounts['fields']) as $field) {
            self::assertDoesNotMatchRegularExpression('/token|secret|senha|password|certificate|key/i', $field, 'Nenhuma credencial bancária no cadastro.');
        }
    }

    public function testCostCenterValidation(): void
    {
        $definition = Definitions::get('cost_centers');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['code', 'name'], array_keys($errors));
        foreach (['PORT', '01', 'obra-b', 'adm.01'] as $code) {
            [, $errors] = RegistryValidator::validate($definition, ['code' => $code, 'name' => 'X']);
            self::assertSame([], $errors, $code);
        }
        foreach (['-x', 'com espaço', str_repeat('a', 21), 'ação'] as $code) {
            [, $errors] = RegistryValidator::validate($definition, ['code' => $code, 'name' => 'X']);
            self::assertArrayHasKey('code', $errors, $code);
        }
    }

    public function testBankAccountValidation(): void
    {
        $definition = Definitions::get('bank_accounts');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['condominium_id', 'name', 'bank_code', 'branch', 'account_number', 'kind'], array_keys($errors));

        $valid = ['condominium_id' => '1', 'name' => 'Movimento', 'bank_code' => '341', 'branch' => '1234', 'account_number' => '12345-6', 'kind' => 'corrente'];
        [$clean, $errors] = RegistryValidator::validate($definition, $valid + ['is_main' => '1', 'opening_balance_cents' => '1.500,00', 'opened_on' => '01/11/2026']);
        self::assertSame([], $errors);
        self::assertSame(1, $clean['is_main']);
        self::assertSame(150000, $clean['opening_balance_cents']);
        self::assertSame('2026-11-01', $clean['opened_on']);

        foreach (['bank_code' => '34', 'branch' => '12a4', 'account_number' => '123456', 'kind' => 'investimento'] as $field => $value) {
            [, $errors] = RegistryValidator::validate($definition, [$field => $value] + $valid);
            self::assertArrayHasKey($field, $errors, "{$field} = {$value}");
        }
        [, $errors] = RegistryValidator::validate($definition, $valid + ['opening_balance_cents' => '100,00']);
        self::assertArrayHasKey('opened_on', $errors, 'Saldo inicial exige a data.');
    }
}
