<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\ApportionmentSample;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\Definitions;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Formas de rateio da fatura, multa e juros do boleto atrasado e baixa em competência fechada (ADR 0640): regras puras.
 * A gravação no MariaDB é conferida pela simulação dos condomínios de teste (tools/lab-rateios.php).
 */
final class ApportionmentMethodsTest extends TestCase
{
    private const UNITS = [
        ['id' => 1, 'label' => '101', 'ideal_fraction' => '0.200000', 'apportion_percent' => '50.0000', 'parking_spots' => 2],
        ['id' => 2, 'label' => '102', 'ideal_fraction' => '0.300000', 'apportion_percent' => '30.0000', 'parking_spots' => 0],
        ['id' => 3, 'label' => '103', 'ideal_fraction' => '0.500000', 'apportion_percent' => '20.0000', 'parking_spots' => 1],
    ];

    public function testEveryMethodKeepsTheTotal(): void
    {
        self::assertSame([1 => 50000, 2 => 50000, 3 => 50000], Invoices::distribute('fixed', 50000, self::UNITS), 'Igualitário: o valor é por unidade.');
        self::assertSame([1 => 334, 2 => 333, 3 => 333], Invoices::distribute('equal', 1000, self::UNITS), 'Por unidade: total ÷ 3, o centavo que sobra vai para a primeira.');
        self::assertSame([1 => 20000, 2 => 30000, 3 => 50000], Invoices::distribute('fraction', 100000, self::UNITS));
        self::assertSame([1 => 50000, 2 => 30000, 3 => 20000], Invoices::distribute('percent', 100000, self::UNITS));
        self::assertSame([1 => 66667, 3 => 33333], Invoices::distribute('parking', 100000, self::UNITS), 'Pelas vagas: 2 e 1; a unidade sem vaga fica fora.');
        self::assertSame([1 => 12000, 2 => 8000, 3 => 1], Invoices::distribute('custom', null, self::UNITS, [1 => 12000, 2 => '80,00', 3 => '0,01']), 'Valores informados.');
        self::assertSame([1 => 40000, 2 => 35000, 3 => 25000], Invoices::distribute('custom', 100000, self::UNITS, [], [1 => '40', 2 => '35', 3 => '25']), 'Percentuais informados.');
        foreach (['equal', 'fraction', 'percent', 'parking'] as $method) {
            self::assertSame(1_935_500, array_sum(Invoices::distribute($method, 1_935_500, self::UNITS)), "{$method} soma o total.");
        }
    }

    public function testRefusesWhatCannotAddUp(): void
    {
        $cases = [
            'percent sem 100%' => fn () => Invoices::distribute('percent', 1000, [['id' => 1, 'apportion_percent' => '60'], ['id' => 2, 'apportion_percent' => '30']]),
            'percent vazio' => fn () => Invoices::distribute('percent', 1000, [['id' => 1, 'apportion_percent' => null, 'label' => 'Loja 1']]),
            'fração vazia' => fn () => Invoices::distribute('fraction', 1000, [['id' => 1, 'ideal_fraction' => '0']]),
            'sem vagas' => fn () => Invoices::distribute('parking', 1000, [['id' => 1, 'parking_spots' => 0]]),
            'personalizado sem valor de uma unidade' => fn () => Invoices::distribute('custom', null, self::UNITS, [1 => 100, 2 => 200]),
            'personalizado com percentuais que não somam 100' => fn () => Invoices::distribute('custom', 1000, self::UNITS, [], [1 => 50, 2 => 30, 3 => 10]),
            'sem total' => fn () => Invoices::distribute('equal', null, self::UNITS),
            'menos de 1 centavo por unidade' => fn () => Invoices::distribute('equal', 2, self::UNITS),
            'forma inválida' => fn () => Invoices::distribute('area', 1000, self::UNITS),
            'sem unidades' => fn () => Invoices::distribute('equal', 1000, []),
        ];
        foreach ($cases as $case => $call) {
            try {
                $call();
                self::fail("Aceitou: {$case}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testBlocksAndDeclaredFields(): void
    {
        self::assertSame(['A', 'Torre B'], Invoices::blocks(' A, Torre B ,a, torre b, '), 'Sem vazios nem repetidos (sem diferença de maiúsculas); fica a primeira grafia.');
        self::assertSame(['C'], Invoices::blocks(['C', '']));
        $this->expectException(InvalidArgumentException::class);
        Invoices::blocks('  ,  ');
    }

    public function testMethodsAndUnitPercentAreDeclared(): void
    {
        self::assertSame(['fixed', 'equal', 'fraction', 'percent', 'parking', 'custom'], array_keys(Invoices::METHODS));
        self::assertSame(['all', 'blocks', 'units'], array_keys(Invoices::PARTICIPANTS));
        $field = Definitions::get('units')['fields']['apportion_percent'];
        self::assertSame(['decimal', 4, 100], [$field['type'], $field['scale'], $field['max_value']]);
    }

    public function testLateBoletoPaysFineAndInterestOnTop(): void
    {
        $rule = ['fine_bp' => 200, 'monthly_interest_bp' => 100];
        $charge = ['status' => 'registered', 'amount_cents' => 100000, 'due_on' => '2026-03-10'];
        // 15 dias de atraso: multa 2% (2.000) + juros 1% a.m. pro rata (500) = 2.500 a mais.
        $event = ['kind' => 'paid', 'amount_cents' => 102500, 'occurred_on' => '2026-03-25'];
        self::assertSame(2500, BankReturns::lateFee($event, $charge, $rule));
        self::assertSame(['applied', 'paid', 2500], array_values(array_intersect_key(BankReturns::decide($event, $charge, $rule), array_flip(['outcome', 'status', 'late_fee_cents']))));
        self::assertSame(2600, BankReturns::lateFee(['amount_cents' => 102600] + $event, $charge, $rule), 'Até R$ 1,00 de arredondamento do banco.');
        self::assertNull(BankReturns::lateFee(['amount_cents' => 102601] + $event, $charge, $rule), 'Acima da multa + juros + folga.');
        self::assertSame('divergence', BankReturns::decide(['amount_cents' => 102601] + $event, $charge, $rule)['outcome']);
        self::assertNull(BankReturns::lateFee(['occurred_on' => '2026-03-10'] + $event, $charge, $rule), 'Pago no vencimento com valor a mais: divergência.');
        self::assertSame('divergence', BankReturns::decide($event, $charge)['outcome'], 'Sem a regra, continua como antes.');
        self::assertNull(BankReturns::lateFee(['amount_cents' => 99000] + $event, $charge, $rule), 'Pago a menos nunca é multa.');
    }

    public function testSettlementOnlyIsRecognized(): void
    {
        $before = ['condominium_id' => 1, 'competence' => '2026-03', 'amount_cents' => 50000, 'paid_on' => null, 'bank_account_id' => null, 'description' => 'Taxa'];
        self::assertSame('2026-05', RegistryService::isSettlementOnly($before, ['paid_on' => '2026-05-02', 'bank_account_id' => 9, 'amount_cents' => '50000'] + $before));
        self::assertNull(RegistryService::isSettlementOnly($before, ['paid_on' => '2026-05-02', 'amount_cents' => 49000] + $before), 'Mudou o valor: não é só baixa.');
        self::assertNull(RegistryService::isSettlementOnly(['paid_on' => '2026-04-01'] + $before, ['paid_on' => '2026-05-02'] + $before), 'Já estava paga: é troca da baixa.');
        self::assertNull(RegistryService::isSettlementOnly($before, $before), 'Sem pagamento.');
        self::assertNull(RegistryService::isSettlementOnly(null, ['paid_on' => '2026-05-02']), 'Cadastro novo.');
    }

    public function testSampleScenariosCoverEveryMethod(): void
    {
        $scenarios = ApportionmentSample::scenarios();
        $methods = [];
        foreach ($scenarios as $s) {
            foreach ($s['lines'] as $line) {
                $methods[$line['method']] = true;
                self::assertArrayHasKey('type', $line);
            }
        }
        ksort($methods);
        self::assertSame(['custom', 'equal', 'fixed', 'fraction', 'parking', 'percent'], array_keys($methods));
        self::assertCount(9, $scenarios, 'Os 8 tipos de rateio e o misto.');
        self::assertSame(['2026-01', '2026-02', '2026-03'], ApportionmentSample::months('2026-03-15'));
        self::assertSame('1,000000', number_format(array_sum(array_map(static fn (string $f): float => (float)str_replace(',', '.', $f), ApportionmentSample::fractions([1 => 92, 2 => 68, 3 => 68, 4 => 140]))), 6, ',', ''));
    }
}
