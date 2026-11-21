<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeSicoobHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankRemittances;
use Source\Services\Erp\Banking\Sicoob\SicoobBankAdapter;

/** Adaptador do Sicoob com transporte falso: nada sai da máquina (ADR 0633). */
final class SicoobBankAdapterTest extends TestCase
{
    private FakeSicoobHttp $http;
    private SicoobBankAdapter $adapter;
    /** @var list<int> */
    private array $waits = [];

    protected function setUp(): void
    {
        $this->http = new FakeSicoobHttp();
        $this->waits = [];
        $this->adapter = $this->adapter();
    }

    private function adapter(array $changes = []): SicoobBankAdapter
    {
        return new SicoobBankAdapter(array_replace(['client_id' => 'cid-teste', 'client_secret' => 'token-sandbox', 'client_number' => '25546454',
            'account_number' => '12345-6', 'modality' => '1'], $changes), $this->http, SicoobBankAdapter::SANDBOX_URL,
            static fn (): int => strtotime('2026-11-21 10:00:00'), function (int $s): void {
                $this->waits[] = $s;
            });
    }

    private function charge(array $changes = []): array
    {
        return array_replace([
            'our_number' => '0000000001', 'amount_cents' => 50000, 'due_on' => '2026-11-30', 'description' => 'Taxa condominial 2026-11',
            'bank_code' => '756', 'branch' => '3069', 'account_number' => '12345-6', 'wallet' => null, 'fine_bp' => 200, 'monthly_interest_bp' => 100,
            'payer' => ['name' => 'Pessoa Teste', 'document' => '111.444.777-35', 'email' => 'pessoa@example.test', 'street' => 'Rua Exemplo',
                'number' => '10', 'complement' => 'A-101', 'district' => 'Centro', 'city' => 'Cidade Teste', 'state' => 'sp', 'zip_code' => '01001-000'],
        ], $changes);
    }

    public function testChargeBodyUsesReaisPixFineAndMonthlyInterest(): void
    {
        $body = SicoobBankAdapter::chargeBody($this->charge(), 25546454, 1, '2026-11-21');
        self::assertSame([25546454, 1, 123456], [$body['numeroCliente'], $body['codigoModalidade'], $body['numeroContaCorrente']]);
        self::assertSame(500.0, $body['valor']);
        self::assertSame(['0000000001', '2026-11-30', '2026-11-21'], [$body['seuNumero'], $body['dataVencimento'], $body['dataEmissao']]);
        self::assertSame([2, '2026-12-01', 2.0], [$body['tipoMulta'], $body['dataMulta'], $body['valorMulta']]);
        self::assertSame([2, '2026-12-01', 1.0], [$body['tipoJurosMora'], $body['dataJurosMora'], $body['valorJurosMora']]);
        self::assertSame(1, $body['codigoCadastrarPIX']);
        self::assertSame([2, 2, false], [$body['identificacaoEmissaoBoleto'], $body['identificacaoDistribuicaoBoleto'], $body['gerarPdf']]);
        self::assertSame(['11144477735', 'SP', '01001000', 'pessoa@example.test'], [$body['pagador']['numeroCpfCnpj'], $body['pagador']['uf'], $body['pagador']['cep'], $body['pagador']['email']]);
        self::assertSame('Rua Exemplo, 10 A-101', $body['pagador']['endereco']);
        $free = SicoobBankAdapter::chargeBody($this->charge(['fine_bp' => 0, 'monthly_interest_bp' => 0]), 1, 1, '2026-11-21');
        self::assertSame([0, 3], [$free['tipoMulta'], $free['tipoJurosMora']]);
        self::assertArrayNotHasKey('dataMulta', $free);
        self::assertSame(['Taxa condominial 2026-11'], $body['mensagensInstrucao']);
    }

    public function testModalityComesFromWalletOrDefaultsToOne(): void
    {
        self::assertSame(1, SicoobBankAdapter::modality(null));
        self::assertSame(1, SicoobBankAdapter::modality('abc'));
        self::assertSame(3, SicoobBankAdapter::modality('03'));
        self::assertSame(1, SicoobBankAdapter::modality('999'));
    }

    public function testMissingDataIsRejectedWithoutCallingTheBank(): void
    {
        $result = $this->adapter->registerCharge($this->charge(['payer' => ['document' => '123'] + $this->charge()['payer']]));
        self::assertSame('rejected', $result['status']);
        self::assertStringContainsString('CPF ou CNPJ', (string)$result['error']);
        $result = $this->adapter->registerCharge($this->charge(['payer' => ['zip_code' => ''] + $this->charge()['payer']]));
        self::assertStringContainsString('Endereço', (string)$result['error']);
        self::assertSame('rejected', $this->adapter->registerCharge($this->charge(['amount_cents' => 0]))['status']);
        self::assertSame([], $this->http->requests);
    }

    public function testRegisterQueryPaidAndCancelFlow(): void
    {
        $result = $this->adapter->registerCharge($this->charge());
        self::assertSame('registered', $result['status']);
        self::assertSame('1001', $result['external_id']);
        self::assertStringStartsWith('75691', (string)$result['digitable_line']);
        self::assertStringContainsString('pix.sicoob.com.br', (string)$result['pix_copy_paste']);
        $request = $this->http->requests[0];
        self::assertSame('https://sandbox.sicoob.com.br/sicoob/sandbox/cobranca-bancaria/v3/boletos', $request['url']);
        self::assertSame(['cid-teste', 'Bearer token-sandbox'], [$request['headers']['client_id'], $request['headers']['Authorization']]);
        self::assertSame('', $request['cert'], 'sandbox sem certificado');

        $query = $this->adapter->getCharge('0000000001', '1001');
        self::assertSame('registered', $query['status']);
        self::assertNull($query['event']);
        self::assertSame(['25546454', '1', '1001'], [$this->http->requests[1]['query']['numeroCliente'], $this->http->requests[1]['query']['codigoModalidade'], $this->http->requests[1]['query']['nossoNumero']]);

        $this->http->pay('1001');
        $paid = $this->adapter->getCharge('0000000001', '1001');
        self::assertSame('paid', $paid['event']['kind']);
        self::assertSame([50000, '2026-11-25', 'sicoob:1001:paid'], [$paid['event']['amount_cents'], $paid['event']['occurred_on'], $paid['event']['event_key']]);
        self::assertSame('rejected', $this->adapter->cancelCharge('0000000001', '1001')['status'], 'boleto pago não baixa');

        $this->adapter->registerCharge($this->charge(['our_number' => '0000000002']));
        self::assertSame(['status' => 'cancelled', 'error' => null], $this->adapter->cancelCharge('0000000002', '1002'));
        $baixar = end($this->http->requests);
        self::assertSame(['numeroCliente' => 25546454, 'codigoModalidade' => 1], json_decode((string)$baixar['body'], true));
        self::assertSame('cancelled', $this->adapter->getCharge('0000000002', '1002')['event']['kind']);
    }

    public function testQueryWithoutOurNumberOrUnknownBoletoStaysPending(): void
    {
        self::assertSame('pending', $this->adapter->getCharge('0000000001', null)['status']);
        self::assertSame([], $this->http->requests);
        $missing = $this->adapter->getCharge('0000000001', '999');
        self::assertSame(['pending', 'Sicoob não encontrou o boleto.'], [$missing['status'], $missing['error']]);
    }

    public function testBankRejectionAndServerErrorAreDistinguished(): void
    {
        $this->http->nextResponse = ['status' => 400, 'body' => '{"mensagens":[{"mensagem":"CEP do pagador inválido","codigo":"4021"}]}'];
        $rejected = $this->adapter->registerCharge($this->charge());
        self::assertSame('rejected', $rejected['status']);
        self::assertStringContainsString('4021 CEP do pagador inválido', (string)$rejected['error']);
        $this->http->nextResponse = ['status' => 500, 'body' => '{}'];
        $this->expectException(RuntimeException::class);
        $this->adapter->registerCharge($this->charge());
    }

    public function testRateLimitWaitsAndRetries(): void
    {
        $this->http->rateLimited = 2;
        self::assertSame('registered', $this->adapter->registerCharge($this->charge())['status']);
        self::assertSame([3, 8], $this->waits);
    }

    public function testMapChargeStrictModeRefusesAnotherBoleto(): void
    {
        $this->expectException(RuntimeException::class);
        SicoobBankAdapter::mapCharge(['seuNumero' => '77', 'situacaoBoleto' => 'Em Aberto', 'linhaDigitavel' => '1'], '0000000001', '5');
    }

    public function testMapChargeUnknownStatusIsPendingWithWarning(): void
    {
        $result = SicoobBankAdapter::mapCharge(['situacaoBoleto' => 'Protestado', 'linhaDigitavel' => '1'], '1', '5', false);
        self::assertSame('pending', $result['status']);
        self::assertStringContainsString('desconhecida', (string)$result['error']);
        self::assertNull($result['event']);
    }

    public function testPdfComesFromSecondCopyInBase64(): void
    {
        self::assertStringStartsWith('%PDF', $this->adapter->downloadBoleto('0000000001', '1001'));
        self::assertSame('true', $this->http->requests[0]['query']['gerarPdf']);
    }

    public function testStatementSplitsByMonthAndBuildsStableFitids(): void
    {
        $this->http->transactions['10/2026'] = [
            ['tipo' => 'CREDITO', 'valor' => '150.25', 'data' => '2026-10-30T00:00:00', 'descricao' => 'Liquidação boleto', 'numeroDocumento' => '1'],
            ['tipo' => 'CREDITO', 'valor' => '150.25', 'data' => '2026-10-30T00:00:00', 'descricao' => 'Liquidação boleto', 'numeroDocumento' => '1'],
            ['tipo' => 'DEBITO', 'valor' => '-20.00', 'data' => '2026-10-31', 'descricao' => 'Tarifa'],
            ['tipo' => 'BLOQUEIO', 'valor' => '5.00', 'data' => '2026-10-31'],
        ];
        $this->http->transactions['11/2026'] = [['tipo' => 'DEBITO', 'valor' => '10.00', 'data' => '2026-11-02', 'descricao' => 'Pix enviado', 'descInfComplementar' => 'Fornecedor']];
        $lines = $this->adapter->statement('2026-10-29', '2026-11-05');
        self::assertCount(4, $lines);
        self::assertSame([15025, 15025, -2000, -1000], array_column($lines, 'amount_cents'));
        self::assertSame($lines[0]['fitid'] . '-2', $lines[1]['fitid']);
        self::assertSame('Pix enviado - Fornecedor', $lines[3]['memo']);
        $paths = array_column($this->http->requests, 'path');
        self::assertSame(['/sicoob/sandbox/conta-corrente/v3/extrato/10/2026', '/sicoob/sandbox/conta-corrente/v3/extrato/11/2026'], $paths);
        self::assertSame(['29', '31'], [$this->http->requests[0]['query']['diaInicial'], $this->http->requests[0]['query']['diaFinal']]);
        self::assertSame(['1', '5'], [$this->http->requests[1]['query']['diaInicial'], $this->http->requests[1]['query']['diaFinal']]);
        self::assertSame('123456', $this->http->requests[0]['query']['numeroContaCorrente']);
    }

    public function testBalanceTodayInCentsAndPastDayIsNull(): void
    {
        self::assertSame(123456, $this->adapter->balance('2026-11-21'));
        self::assertNull($this->adapter->balance('2026-11-20'));
        $this->http->balance = '-15.50';
        self::assertSame(-1550, $this->adapter->balance('2026-11-21'));
    }

    public function testAuthenticationCheckAndRefusal(): void
    {
        $this->adapter->checkAuthentication();
        $this->http->authStatus = 401;
        try {
            $this->adapter->checkAuthentication();
            self::fail('deveria recusar');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('recusou a autenticação', $error->getMessage());
            self::assertStringContainsString('client ID ou o access token', BankCredentialSetup::diagnose($error->getMessage(), 'sicoob'));
        }
    }

    public function testSimulationIsNotAvailableAndNoReturnFile(): void
    {
        try {
            $this->adapter->simulatePayment('1001');
            self::fail('o sandbox do Sicoob não simula pagamento');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('não simula', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->parseReturn('qualquer');
    }

    public function testOnlySandboxAndCompleteCredentialsAreAccepted(): void
    {
        try {
            new SicoobBankAdapter(['client_id' => 'a', 'client_secret' => 'b', 'client_number' => '1'], $this->http, SicoobBankAdapter::PRODUCTION_URL);
            self::fail('produção não está liberada');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('sandbox', $error->getMessage());
        }
        foreach (['client_id', 'client_secret', 'client_number'] as $missing) {
            try {
                $this->adapter([$missing => '']);
                self::fail("faltando {$missing} deveria recusar");
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        $this->adapter(['client_number' => '12-AB']);
    }

    public function testCatalogKnowsSicoob(): void
    {
        self::assertContains('sicoob', BankAdapters::NAMES);
        self::assertContains('sicoob', BankAdapters::REAL);
        self::assertSame('756', BankCredentials::ADAPTERS['sicoob']);
        self::assertSame('sicoob', BankCredentialSetup::bankOf('756'));
        self::assertContains('sicoob', BankCredentials::WITHOUT_CERTIFICATE);
        self::assertArrayHasKey('sicoob', BankRemittances::MODES);
        $this->expectException(InvalidArgumentException::class);
        BankAdapters::get('sicoob');
    }
}
