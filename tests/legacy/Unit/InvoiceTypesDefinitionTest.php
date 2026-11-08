<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Core\View;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Tipos de faturas (AP-168) e campo 'ref', sem banco (ADR 0020). */
final class InvoiceTypesDefinitionTest extends TestCase
{
    public function testDefinitionPointsToAnalyticRevenueAccounts(): void
    {
        $definition = Definitions::get('invoice_types');
        self::assertSame('configuracoes', $definition['group']);
        self::assertSame('optional', $definition['scope']);
        $account = $definition['fields']['chart_account_id'];
        self::assertSame('ref', $account['type']);
        self::assertSame('chart', $account['ref']);
        self::assertSame(['kind' => 'analitica', 'nature' => 'receita'], $account['ref_where']);
        self::assertNotNull(Definitions::get($account['ref']), 'O cadastro apontado existe.');
    }

    public function testValidation(): void
    {
        $definition = Definitions::get('invoice_types');
        [, $errors] = RegistryValidator::validate($definition, []);
        self::assertSame(['name', 'chart_account_id', 'recurrence'], array_keys($errors));

        [$clean, $errors] = RegistryValidator::validate($definition, ['name' => 'Taxa condominial', 'chart_account_id' => '12', 'recurrence' => 'mensal', 'default_amount_cents' => '1.250,00']);
        self::assertSame([], $errors);
        self::assertSame(12, $clean['chart_account_id']);
        self::assertSame(125000, $clean['default_amount_cents']);

        [, $errors] = RegistryValidator::validate($definition, ['name' => 'X', 'chart_account_id' => 'abc', 'recurrence' => 'semanal']);
        self::assertArrayHasKey('chart_account_id', $errors);
        self::assertArrayHasKey('recurrence', $errors);
    }

    public function testRefSelectGroupsStandardFirstAndEscapes(): void
    {
        $field = Definitions::get('invoice_types')['fields']['chart_account_id'];
        $html = (new View(moves_container_path('erp', 'connect') . '/'))->render('components/forms/registry-field', [
            'field' => $field, 'value' => 5, 'error' => null, 'readonly' => false,
            'condominiums' => [['id' => 3, 'name' => 'Alpha']],
            'refOptions' => [
                ['id' => 5, 'condominium_id' => null, 'label' => '1.01 — Taxa condominial'],
                ['id' => 9, 'condominium_id' => 3, 'label' => '1.90 — <b>Salão</b>'],
            ],
        ]);
        self::assertStringContainsString('<optgroup label="Padrão da Connect">', $html);
        self::assertStringContainsString('<optgroup label="Alpha">', $html);
        self::assertStringContainsString('<option value="5" selected>1.01 — Taxa condominial</option>', $html);
        self::assertStringContainsString('&lt;b&gt;Salão&lt;/b&gt;', $html);
        self::assertLessThan(strpos($html, 'Alpha'), strpos($html, 'Padrão da Connect'));
    }
}
