<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\LateCharges;
use Source\Services\Erp\PaymentReports;

/** Pagamentos, memória de cálculo e transferências (ADR 0089): passos do índice e CSV seguro. */
final class PaymentReportsTest extends TestCase
{
    public function testIndexStepsMatchLateChargesFactor(): void
    {
        $rates = ['2026-08' => '1.0', '2026-10' => '0.5'];
        $steps = PaymentReports::indexSteps('2026-08-10', '2026-11-09', 'IPCA', $rates);
        self::assertSame([
            ['competence' => '2026-08', 'rate_percent' => '1.0000', 'factor' => '1.010000'],
            ['competence' => '2026-09', 'rate_percent' => null, 'factor' => '1.010000'],
            ['competence' => '2026-10', 'rate_percent' => '0.5000', 'factor' => '1.015050'],
        ], $steps, 'Mês sem taxa não muda o fator.');
        $charges = LateCharges::compute(100000, '2026-08-10', '2026-11-09', ['fine_bp' => 200, 'monthly_interest_bp' => 100, 'price_index' => 'IPCA'], $rates);
        self::assertSame((int)round(100000 * 1.01505) - 100000, $charges['correction_cents'], 'A memória usa o mesmo fator da cobrança.');
        self::assertSame(['2026-09'], $charges['missing_index']);
        self::assertSame([], PaymentReports::indexSteps('2026-08-10', '2026-11-09', 'NONE', $rates), 'Sem índice, sem passos.');
    }

    public function testCsvIsSafe(): void
    {
        $table = PaymentReports::csvTable('payments', ['rows' => [['condominium_name' => 'Alpha', 'nature' => 'despesa', 'paid_on' => '2026-11-01', 'due_on' => '2026-10-30',
            'description' => '=cmd|"/c calc"!A1', 'document_number' => '+123', 'account' => '2.01 Manutenção', 'bank_account' => 'Movimento', 'supplier' => '@Fornecedor',
            'supplier_document' => '************81', 'unit_label' => null, 'amount_cents' => 5000]]]);
        self::assertSame("'=cmd|\"/c calc\"!A1", $table['rows'][0][4]);
        self::assertSame("'+123", $table['rows'][0][5]);
        self::assertSame("'@Fornecedor", $table['rows'][0][8]);
        self::assertSame('', $table['rows'][0][10]);
        $calc = PaymentReports::csvTable('calculation', ['rows' => [['unit_label' => '101', 'missing_index' => ['2026-09', '2026-10']]]]);
        self::assertSame('2026-09 2026-10', $calc['rows'][0][10]);
        self::assertCount(8, PaymentReports::csvTable('transfers', ['rows' => [[]]])['header']);
        $this->expectException(InvalidArgumentException::class);
        PaymentReports::csvTable('boletos', ['rows' => []]);
    }
}
