<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Lançamentos fixos (AP-021) e regras puras da geração, sem banco (ADR 0027). */
final class RecurringEntriesDefinitionTest extends TestCase
{
    private const VALID = [
        'condominium_id' => '1', 'nature' => 'despesa', 'description' => 'Portaria terceirizada', 'amount_cents' => '8.500,00',
        'chart_account_id' => '9', 'day_of_month' => '10', 'starts_competence' => '2026-11',
    ];

    public function testDefinitionReusesEntryRules(): void
    {
        $definition = Definitions::get('recurring_entries');
        self::assertSame(['AP-021', 'financeiro', 'required', 'erp_recurring_entries'], [$definition['page'], $definition['group'], $definition['scope'], $definition['table']]);
        self::assertSame(['nature' => 'nature'], $definition['fields']['chart_account_id']['ref_match']);
        self::assertArrayNotHasKey('paid_on', $definition['fields'], 'O modelo não é pago; o lançamento gerado nasce em aberto.');
        self::assertArrayNotHasKey('competence', $definition['fields']);
    }

    public function testValidation(): void
    {
        $definition = Definitions::get('recurring_entries');
        [$clean, $errors] = RegistryValidator::validate($definition, self::VALID);
        self::assertSame([], $errors);
        self::assertSame(850000, $clean['amount_cents']);
        self::assertSame(10, $clean['day_of_month']);
        self::assertNull($clean['ends_competence']);

        // ADR 0152: 11/2026 e nov/2026 passaram a valer (viram 2026-11); mês inexistente continua recusado.
        foreach (['day_of_month' => ['0', '29', '31'], 'starts_competence' => ['2026-13', '13/2026', 'xyz/2026'], 'amount_cents' => ['0']] as $field => $values) {
            foreach ($values as $value) {
                [, $errors] = RegistryValidator::validate($definition, [$field => $value] + self::VALID);
                self::assertArrayHasKey($field, $errors, "{$field} = {$value}");
            }
        }
        [, $errors] = RegistryValidator::validate($definition, ['ends_competence' => '2026-10'] + self::VALID);
        self::assertArrayHasKey('ends_competence', $errors, 'Fim antes do início.');
        [, $errors] = RegistryValidator::validate($definition, ['ends_competence' => '2026-11'] + self::VALID);
        self::assertSame([], $errors, 'Fim igual ao início (um mês só).');
    }

    public function testPureGenerationRules(): void
    {
        self::assertTrue(RecurringEntries::isCompetence('2026-02'));
        self::assertFalse(RecurringEntries::isCompetence('2026-2'));
        self::assertFalse(RecurringEntries::isCompetence('2026-00'));
        self::assertSame('2026-02-28', RecurringEntries::dueDate('2026-02', 28));
        self::assertSame('2027-01-05', RecurringEntries::dueDate('2027-01', 5));

        self::assertTrue(RecurringEntries::appliesTo('2026-11', '2026-11', null));
        self::assertTrue(RecurringEntries::appliesTo('2027-03', '2026-11', '2027-03'));
        self::assertFalse(RecurringEntries::appliesTo('2026-10', '2026-11', null), 'Antes do início.');
        self::assertFalse(RecurringEntries::appliesTo('2027-04', '2026-11', '2027-03'), 'Depois do fim.');

        $this->expectException(InvalidArgumentException::class);
        RecurringEntries::dueDate('2026-11', 29);
    }
}
