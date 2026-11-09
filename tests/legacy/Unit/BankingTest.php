<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;

/** Boletos e retorno: adaptador sandbox, parser do retorno e regra da conciliação (ADR 0035). */
final class BankingTest extends TestCase
{
    public function testSandboxRegistersRejectsAndSimulatesTimeoutWithoutRealData(): void
    {
        $adapter = new SandboxBankAdapter();
        $charge = ['our_number' => '0000000007', 'amount_cents' => 50000, 'due_on' => '2026-11-10', 'payer_name' => 'A-101',
            'description' => 'Taxa', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'wallet' => null];
        $ok = $adapter->registerCharge($charge);
        self::assertSame('registered', $ok['status']);
        self::assertStringStartsWith('SANDBOX', $ok['digitable_line'], 'Linha digitável do sandbox não é pagável.');
        self::assertSame('rejected', $adapter->registerCharge(['description' => 'x [sandbox:rejeitar]'] + $charge)['status']);
        self::assertSame('pending', $adapter->registerCharge(['description' => 'x [sandbox:pendente]'] + $charge)['status']);
        self::assertSame('registered', $adapter->getCharge('0000000007', null)['status'], 'Consulta confirma o pendente.');
        self::assertSame('sandbox', BankAdapters::get()->name());
    }

    public function testUnknownAdapterIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BankAdapters::get('banco-real');
    }

    public function testReturnRoundTripAndValidation(): void
    {
        $adapter = new SandboxBankAdapter();
        $content = SandboxBankAdapter::buildReturn([
            ['event_key' => 'EV1', 'kind' => 'paid', 'our_number' => '0000000001', 'amount_cents' => 50000, 'occurred_on' => '2026-11-09'],
            ['event_key' => 'EV2', 'kind' => 'registered', 'our_number' => '0000000002', 'amount_cents' => null, 'occurred_on' => null],
        ]);
        $events = $adapter->parseReturn($content);
        self::assertSame(['EV1', 'EV2'], array_column($events, 'event_key'));
        self::assertSame([50000, null], array_column($events, 'amount_cents'));

        $broken = [
            'layout' => "CNAB240\nTOTAL;0;0\n",
            'trailer' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100;2026-11-09\n",
            'totais' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100;2026-11-09\nTOTAL;1;999\n",
            'campos' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100\nTOTAL;1;100\n",
            'repetido' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100;2026-11-09\nEV1;paid;1;100;2026-11-09\nTOTAL;2;200\n",
            'data' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100;2026-02-30\nTOTAL;1;100\n",
            'ocorrencia' => "SANDBOX-RETORNO;v1\nEV1;estorno;1;100;2026-11-09\nTOTAL;1;100\n",
            'encoding' => "SANDBOX-RETORNO;v1\nEV1;paid;1;100;2026-11-09\nTOTAL;1;100\n\xff",
        ];
        foreach ($broken as $case => $text) {
            try {
                $adapter->parseReturn($text);
                self::fail("Retorno malformado aceito: {$case}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testReconciliationRuleNeverSettlesSilently(): void
    {
        $paid = ['kind' => 'paid', 'amount_cents' => 50000];
        self::assertSame('applied', BankReturns::decide($paid, ['status' => 'registered', 'amount_cents' => '50000'])['outcome']);
        self::assertSame('paid', BankReturns::decide($paid, ['status' => 'pending', 'amount_cents' => 50000])['status']);
        self::assertSame('divergence', BankReturns::decide(['kind' => 'paid', 'amount_cents' => 40000], ['status' => 'registered', 'amount_cents' => 50000])['outcome'], 'Pagamento parcial.');
        self::assertSame('divergence', BankReturns::decide($paid, ['status' => 'paid', 'amount_cents' => 50000])['outcome'], 'Pago duas vezes.');
        self::assertSame('divergence', BankReturns::decide($paid, ['status' => 'cancelled', 'amount_cents' => 50000])['outcome']);
        self::assertSame('unknown_charge', BankReturns::decide($paid, null)['outcome']);
        self::assertSame('duplicate', BankReturns::decide(['kind' => 'registered', 'amount_cents' => null], ['status' => 'registered', 'amount_cents' => 1])['outcome']);
        self::assertSame('rejected', BankReturns::decide(['kind' => 'rejected', 'amount_cents' => null], ['status' => 'pending', 'amount_cents' => 1])['status']);
        self::assertSame('divergence', BankReturns::decide(['kind' => 'cancelled', 'amount_cents' => null], ['status' => 'paid', 'amount_cents' => 1])['outcome']);
    }

    public function testOurNumberFormat(): void
    {
        self::assertSame('0000000042', BankCharges::formatOurNumber(42));
        $this->expectException(InvalidArgumentException::class);
        BankCharges::formatOurNumber(0);
    }
}
