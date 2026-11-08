<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\BankBalances;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryValidator;

/** Contas a pagar/receber como visões dos lançamentos e regra do saldo, sem banco (ADR 0024). */
final class PayablesBalancesDefinitionTest extends TestCase
{
    public function testViewsUseTheEntriesTableWithFixedNatureAndOpenFilter(): void
    {
        foreach (['payables' => ['AP-022', 'despesa'], 'receivables' => ['AP-023', 'receita']] as $slug => [$page, $nature]) {
            $view = Definitions::get($slug);
            self::assertSame($page, $view['page']);
            self::assertSame('erp_entries', $view['table'], 'Sem segunda tabela.');
            self::assertSame('erp.entries', $view['permission']);
            self::assertSame(['nature' => $nature, 'paid_on' => null], $view['base_where']);
            self::assertSame(['nature' => $nature], $view['fixed']);
            self::assertArrayNotHasKey('nature', $view['fields'], 'A natureza não vem do formulário.');
            self::assertSame($nature, $view['fields']['chart_account_id']['ref_where']['nature']);
            self::assertSame('analitica', $view['fields']['chart_account_id']['ref_where']['kind']);
        }
        self::assertArrayHasKey('nature', Definitions::get('entries')['fields'], 'Lançamentos continuam com a natureza.');
    }

    public function testViewValidationIgnoresNatureFromInput(): void
    {
        [$clean, $errors] = RegistryValidator::validate(Definitions::get('payables'), [
            'condominium_id' => '1', 'competence' => '2026-11', 'description' => 'Elevador', 'amount_cents' => '900,00',
            'chart_account_id' => '4', 'due_on' => '2026-11-20', 'nature' => 'receita',
        ]);
        self::assertSame([], $errors);
        self::assertArrayNotHasKey('nature', $clean, 'O valor fixo é aplicado pelo serviço, não pela entrada.');
    }

    public function testBalanceRule(): void
    {
        self::assertSame(150000, BankBalances::balance(100000, 80000, 30000));
        self::assertSame(-5000, BankBalances::balance(0, 0, 5000), 'Saldo pode ficar negativo.');
        self::assertSame(0, BankBalances::balance(0, 0, 0));
    }
}
