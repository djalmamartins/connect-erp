<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Lançamentos (AP-020), sem banco (ADR 0022). */
final class EntriesDefinitionTest extends TestCase
{
    private const VALID = [
        'condominium_id' => '1', 'nature' => 'despesa', 'competence' => '2026-11', 'description' => 'Conta de água',
        'amount_cents' => '1.234,56', 'chart_account_id' => '7', 'due_on' => '10/11/2026',
    ];

    public function testDefinitionReferencesTheFinancialRegistries(): void
    {
        $definition = Definitions::get('entries');
        self::assertSame(['AP-020', 'financeiro', 'required'], [$definition['page'], $definition['group'], $definition['scope']]);
        $refs = [];
        foreach ($definition['fields'] as $name => $field) {
            if ($field['type'] === 'ref') {
                $refs[$name] = $field['ref'];
                self::assertNotNull(Definitions::get($field['ref']), $name);
            }
        }
        self::assertSame(['chart_account_id' => 'chart', 'cost_center_id' => 'cost_centers', 'bank_account_id' => 'bank_accounts', 'supplier_id' => 'suppliers'], $refs);
        self::assertSame(['kind' => 'analitica'], $definition['fields']['chart_account_id']['ref_where']);
        self::assertSame(['nature' => 'nature'], $definition['fields']['chart_account_id']['ref_match']);
        self::assertSame(['active' => 'Válido', 'inactive' => 'Cancelado'], $definition['status']);
    }

    public function testRequiredFieldsAndNormalization(): void
    {
        $definition = Definitions::get('entries');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['condominium_id', 'nature', 'competence', 'description', 'amount_cents', 'chart_account_id', 'due_on'], array_keys($errors));

        [$clean, $errors] = RegistryValidator::validate($definition, self::VALID);
        self::assertSame([], $errors);
        self::assertSame(123456, $clean['amount_cents']);
        self::assertSame('2026-11-10', $clean['due_on']);
        self::assertNull($clean['paid_on']);
    }

    public function testInvalidValuesAndPaymentNeedsBankAccount(): void
    {
        $definition = Definitions::get('entries');
        foreach (['amount_cents' => ['0', '0,00', '-5'], 'competence' => ['2026-13', '11/2026', '2026-1'], 'nature' => ['outra']] as $field => $values) {
            foreach ($values as $value) {
                [, $errors] = RegistryValidator::validate($definition, [$field => $value] + self::VALID);
                self::assertArrayHasKey($field, $errors, "{$field} = {$value}");
            }
        }
        [, $errors] = RegistryValidator::validate($definition, ['paid_on' => '2026-11-09'] + self::VALID);
        self::assertArrayHasKey('bank_account_id', $errors, 'Pago sem conta bancária.');
        [, $errors] = RegistryValidator::validate($definition, ['paid_on' => '2026-11-09', 'bank_account_id' => '3'] + self::VALID);
        self::assertSame([], $errors);
    }
}
