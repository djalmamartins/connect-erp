<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Competência com seletor de mês e ano (ADR 0152): o servidor aceita 11/2026 e nov/2026 e grava AAAA-MM. */
final class MonthCompetenceFieldTest extends TestCase
{
    private const THEME = __DIR__ . '/../../../container/erp/connect/v1/';

    public function testMonthTextIsNormalizedToYearMonth(): void
    {
        foreach (['2026-11' => '2026-11', '11/2026' => '2026-11', '3/2027' => '2027-03', '03-2027' => '2027-03', 'nov/2026' => '2026-11',
            'Nov/2026' => '2026-11', 'novembro de 2026' => '2026-11', 'março/2027' => '2027-03', 'dez 2026' => '2026-12'] as $typed => $expected) {
            self::assertSame($expected, RegistryValidator::month($typed), $typed);
        }
        foreach (['13/2026', 'xyz/2026', '2026', ''] as $invalid) {
            self::assertSame($invalid, RegistryValidator::month($invalid), 'Fica como veio para o pattern recusar: ' . $invalid);
        }
    }

    public function testCompetenceFieldsUseTheMonthPicker(): void
    {
        self::assertTrue(Definitions::get('entries')['fields']['competence']['month'] ?? false);
        self::assertTrue(Definitions::get('payables')['fields']['competence']['month'] ?? false);
        self::assertTrue(Definitions::get('recurring_entries')['fields']['starts_competence']['month'] ?? false);
        self::assertTrue(Definitions::get('recurring_entries')['fields']['ends_competence']['month'] ?? false);

        $definition = Definitions::get('payables');
        $valid = ['condominium_id' => '1', 'competence' => 'nov/2026', 'description' => 'Manutenção elétrica',
            'amount_cents' => '350,00', 'chart_account_id' => '7', 'due_on' => '20/11/2026'];
        [$clean, $errors] = RegistryValidator::validate($definition, $valid);
        self::assertSame([], $errors);
        self::assertSame('2026-11', $clean['competence']);
        [, $errors] = RegistryValidator::validate($definition, ['competence' => '13/2026'] + $valid);
        self::assertSame('Escolha o mês e o ano, ex.: nov/2026.', $errors['competence']);
    }

    public function testFieldTemplateAndScriptsWireThePickerAndTheOptionFilter(): void
    {
        $field = (string)file_get_contents(self::THEME . 'components/forms/registry-field.php');
        self::assertStringContainsString('data-erp-month', $field);
        self::assertStringContainsString('data-erp-ref-filter', $field);
        self::assertStringContainsString('data-match-', $field);
        self::assertStringContainsString('ref_empty', $field);
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $script) {
            $js = (string)file_get_contents(self::THEME . $script);
            self::assertStringContainsString('erp-month__panel', $js, $script);
            self::assertStringContainsString('data-erp-ref-filter', $js, $script);
        }
        self::assertStringContainsString('.erp-month__panel', (string)file_get_contents(self::THEME . 'assets/erp.min.css'));
        self::assertStringContainsString('erp-alert__list', (string)file_get_contents(self::THEME . 'pages/registry/form.php'));
    }
}
