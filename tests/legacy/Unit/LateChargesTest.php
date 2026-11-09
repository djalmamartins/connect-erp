<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\BillingRules;
use Source\Services\Erp\LateCharges;

/** Multa, juros, correção e quebra de acordo: regras puras (ADR 0040). */
final class LateChargesTest extends TestCase
{
    public function testDefaultsFineInterestProRataAndIndex(): void
    {
        $rule = LateCharges::DEFAULT_RULE;
        // 100.000 centavos vencidos em 10/09, calculados em 10/11: 61 dias; IPCA de setembro e outubro.
        $c = LateCharges::compute(100000, '2026-09-10', '2026-11-10', $rule, ['2026-09' => 0.5, '2026-10' => '0.4']);
        self::assertSame(61, $c['days_late']);
        self::assertSame(902, $c['correction_cents'], '100000 × 1,005 × 1,004 = 100902');
        self::assertSame(2018, $c['fine_cents'], '2% de 100902');
        self::assertSame(2052, $c['interest_cents'], '1% ao mês × 61/30 sobre 100902');
        self::assertSame(902 + 2018 + 2052, $c['charges_cents']);
        self::assertSame(100000 + $c['charges_cents'], $c['total_cents']);
        self::assertSame([], $c['missing_index']);
    }

    public function testMissingIndexIsZeroAndFlagged(): void
    {
        $c = LateCharges::compute(100000, '2026-09-10', '2026-11-10', LateCharges::DEFAULT_RULE, ['2026-09' => 0.5]);
        self::assertSame(500, $c['correction_cents']);
        self::assertSame(['2026-10'], $c['missing_index']);
        $none = LateCharges::compute(100000, '2026-09-10', '2026-11-10', ['price_index' => 'NONE'] + LateCharges::DEFAULT_RULE);
        self::assertSame([0, []], [$none['correction_cents'], $none['missing_index']], 'Sem índice configurado não sinaliza falta.');
        self::assertSame(2000, $none['fine_cents']);
    }

    public function testNotLateMeansNoCharges(): void
    {
        $c = LateCharges::compute(5000, '2026-11-10', '2026-11-10', LateCharges::DEFAULT_RULE);
        self::assertSame([0, 0, 5000], [$c['days_late'], $c['charges_cents'], $c['total_cents']]);
        self::assertSame(['2026-09', '2026-10'], LateCharges::indexMonths('2026-09-30', '2026-11-01'));
        self::assertSame([], LateCharges::indexMonths('2026-11-05', '2026-11-20'), 'Índice do mês corrente ainda não saiu.');
    }

    public function testBreachRule(): void
    {
        $parcel = static fn (int $n, string $due, ?string $paid = null, string $status = 'active'): array => ['number' => $n, 'due_on' => $due, 'paid_on' => $paid, 'status' => $status];
        $rule = LateCharges::DEFAULT_RULE;
        self::assertFalse(LateCharges::breach([$parcel(1, '2026-10-20'), $parcel(2, '2026-11-20')], $rule, '2026-11-10')['due'], '21 dias e uma vencida: ainda não.');
        $late = LateCharges::breach([$parcel(1, '2026-10-05')], $rule, '2026-11-10');
        self::assertTrue($late['due']);
        self::assertSame(36, $late['max_days_late']);
        self::assertTrue(LateCharges::breach([$parcel(1, '2026-10-20'), $parcel(2, '2026-11-05')], $rule, '2026-11-10')['due'], 'Duas vencidas em aberto.');
        self::assertFalse(LateCharges::breach([$parcel(1, '2026-09-01', '2026-09-01'), $parcel(2, '2026-10-01', null, 'inactive')], $rule, '2026-11-10')['due'], 'Paga ou cancelada não conta.');
        self::assertFalse(LateCharges::breach([$parcel(1, '2026-10-20'), $parcel(2, '2026-11-05')], ['breach_open_installments' => 0] + $rule, '2026-11-10')['due'], '0 desliga o critério de parcelas.');
    }

    public function testRuleValidationAndIndexFile(): void
    {
        self::assertEquals(LateCharges::DEFAULT_RULE, BillingRules::normalize([]), 'Padrão: 2%, 1% ao mês, IPCA, 30 dias, 2 parcelas.');
        self::assertSame('NONE', BillingRules::normalize(['price_index' => 'none'])['price_index']);
        foreach ([['fine_bp' => 1001], ['monthly_interest_bp' => -1], ['breach_days_late' => 0], ['price_index' => 'IGPM'], ['fine_bp' => '2%']] as $bad) {
            try {
                BillingRules::normalize($bad);
                self::fail('Regra inválida aceita: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame(['2026-09' => 0.48, '2026-10' => -0.02, '2026-11' => 0.3], BillingRules::parseIndexFile("# IPCA\n2026-09;0,48\r\n2026-10;-0.02\n\n2026-11\t0,3\n"));
        foreach (["", "2026-13;0,4", "2026-09;0,4\n2026-09;0,5", "2026-09;abc", "2026-09;150"] as $bad) {
            try {
                BillingRules::parseIndexFile($bad);
                self::fail('Arquivo de índice inválido aceito: ' . json_encode($bad));
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
