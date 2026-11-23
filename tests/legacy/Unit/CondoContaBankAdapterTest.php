<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeCondoContaHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankRemittances;
use Source\Services\Erp\Banking\CondoConta\CondoContaBankAdapter;

/** Adaptador da CondoConta com transporte falso: nada sai da máquina (ADR 0649). */
final class CondoContaBankAdapterTest extends TestCase
{
    private FakeCondoContaHttp $http;
    private CondoContaBankAdapter $adapter;
    /** @var list<int> */
    private array $waits = [];
    private int $now;

    protected function setUp(): void
    {
        $this->http = new FakeCondoContaHttp();
        $this->waits = [];
        $this->now = strtotime('2026-11-23 10:00:00');
        $this->adapter = $this->adapter();
    }

    private function adapter(array $changes = []): CondoContaBankAdapter
    {
        return new CondoContaBankAdapter(array_replace(['client_id' => FakeCondoContaHttp::CLIENT_ID, 'client_secret' => FakeCondoContaHttp::CLIENT_SECRET,
            'account_number' => '12345-6'], $changes), $this->http, CondoContaBankAdapter::SANDBOX_URL,
            fn (): int => $this->now, function (int $s): void {
                $this->waits[] = $s;
            });
    }

    private function charge(array $changes = []): array
    {
        return array_replace([
            'our_number' => '0000000001', 'amount_cents' => 50000, 'due_on' => '2026-11-30', 'description' => 'Taxa condominial 2026-11',
            'bank_code' => CondoContaBankAdapter::BANK_CODE, 'branch' => '0001', 'account_number' => '12345-6', 'wallet' => null, 'fine_bp' => 200, 'monthly_interest_bp' => 100,
            'payer' => ['name' => 'Pessoa Teste', 'document' => '111.444.777-35', 'email' => 'pessoa@example.test', 'street' => 'Rua Exemplo',
                'number' => '10', 'complement' => 'A-101', 'district' => 'Centro', 'city' => 'Cidade Teste', 'state' => 'sp', 'zip_code' => '01001-000'],
        ], $changes);
    }

    public function testChargeBodyUsesReaisFineAndMonthlyInterest(): void
    {
        $body = CondoContaBankAdapter::chargeBody($this->charge());
        self::assertSame(['CONDOMINIUM', '0000000001', '500.00', '2026-11-30', 60], [$body['type'], $body['your_number'], $body['value'], $body['due_date'], $body['expiration_days']]);
        self::assertSame(['type' => 'PERCENT_VALUE', 'value' => '2.00', 'date' => '2026-12-01'], $body['fine']);
        self::assertSame(['type' => 'MONTHLY_VALUE', 'value_type' => 'PERCENT_VALUE', 'value' => '1.000', 'date' => '2026-12-01'], $body['interest']);
        self::assertSame(['11144477735', 'SP', '01001000', 'pessoa@example.test', 'Rua Exemplo', '10', 'A-101', 'Centro'], [$body['payer']['document_number'],
            $body['payer']['state'], $body['payer']['zipcode'], $body['payer']['email'], $body['payer']['address'], $body['payer']['number'],
            $body['payer']['complement'], $body['payer']['neighborhood']]);
        self::assertSame('Taxa condominial 2026-11', $body['message']);
        $free = CondoContaBankAdapter::chargeBody($this->charge(['fine_bp' => 0, 'monthly_interest_bp' => 0, 'payer' => ['number' => '', 'complement' => '', 'email' => 'x'] + $this->charge()['payer']]));
        self::assertArrayNotHasKey('fine', $free);
        self::assertArrayNotHasKey('interest', $free);
        self::assertSame('S/N', $free['payer']['number']);
        self::assertArrayNotHasKey('complement', $free['payer']);
        self::assertArrayNotHasKey('email', $free['payer']);
    }

    public function testMissingDataIsRejectedWithoutCallingTheBank(): void
    {
        $result = $this->adapter->registerCharge($this->charge(['payer' => ['document' => '123'] + $this->charge()['payer']]));
        self::assertSame('rejected', $result['status']);
        self::assertStringContainsString('CPF ou CNPJ', (string)$result['error']);
        self::assertStringContainsString('Endereço', (string)$this->adapter->registerCharge($this->charge(['payer' => ['zip_code' => ''] + $this->charge()['payer']]))['error']);
        self::assertStringContainsString('R$ 2,50', (string)$this->adapter->registerCharge($this->charge(['amount_cents' => 249]))['error']);
        self::assertSame('rejected', $this->adapter->registerCharge($this->charge(['amount_cents' => 25000001]))['status']);
        self::assertSame('rejected', $this->adapter->registerCharge($this->charge(['our_number' => '12345678901234567']))['status']);
        self::assertSame('rejected', $this->adapter->registerCharge($this->charge(['due_on' => '30/11/2026']))['status']);
        self::assertSame([], $this->http->requests);
    }

    public function testRegisterQueryPaidAndCancelFlow(): void
    {
        $result = $this->adapter->registerCharge($this->charge());
        self::assertSame('registered', $result['status']);
        self::assertSame(['5001', '5001'], [$result['external_id'], $result['bank_our_number']]);
        self::assertStringStartsWith('00190000090', (string)$result['digitable_line']);
        self::assertStringContainsString('br.gov.bcb.pix', (string)$result['pix_copy_paste']);
        [$token, $slip] = $this->http->requests;
        self::assertSame(['POST', 'https://api-open-sandbox.condoconta.net/token'], [$token['method'], $token['url']]);
        parse_str((string)$token['body'], $form);
        self::assertSame(['client_credentials', CondoContaBankAdapter::SCOPES], [$form['grant_type'], $form['scope']]);
        self::assertSame('https://api-open-sandbox.condoconta.net/slip', $slip['url']);
        self::assertSame(['Bearer tok-1', 'application/json'], [$slip['headers']['Authorization'], $slip['headers']['Content-Type']]);
        self::assertSame('', $slip['cert'], 'CondoConta sem certificado');

        $query = $this->adapter->getCharge('0000000001', '5001');
        self::assertSame('registered', $query['status']);
        self::assertNull($query['event']);
        self::assertSame(1, $this->http->tokensIssued, 'o token fica guardado na instância');

        $this->http->pay('5001', '2026-11-25', '505.10');
        $paid = $this->adapter->getCharge('0000000001', '5001');
        self::assertSame(['paid', 50510, '2026-11-25', 'condoconta:5001:paid'], [$paid['event']['kind'], $paid['event']['amount_cents'], $paid['event']['occurred_on'], $paid['event']['event_key']]);
        self::assertSame('rejected', $this->adapter->cancelCharge('0000000001', '5001')['status'], 'cobrança paga não cancela');

        $this->adapter->registerCharge($this->charge(['our_number' => '0000000002']));
        self::assertSame(['status' => 'cancelled', 'error' => null], $this->adapter->cancelCharge('0000000002', '5002'));
        self::assertSame('/slip/5002/cancel', end($this->http->requests)['path']);
        $cancelled = $this->adapter->getCharge('0000000002', '5002');
        self::assertSame(['cancelled', '2026-11-23'], [$cancelled['event']['kind'], $cancelled['event']['occurred_on']]);
    }

    public function testIssuingTwiceReturnsTheSameCharge(): void
    {
        $first = $this->adapter->registerCharge($this->charge());
        $again = $this->adapter->registerCharge($this->charge());
        self::assertSame($first['external_id'], $again['external_id']);
        self::assertCount(1, $this->http->slips);
    }

    public function testPaidDateFallsBackToPaymentsList(): void
    {
        $result = CondoContaBankAdapter::mapCharge(['status' => 'PAID', 'value' => 100.5, 'your_number' => '1', 'digitable_line' => '1',
            'payments' => [['paid_at' => '2026-11-20T10:00:00'], ['paid_at' => '2026-11-22T09:00:00']]], '0001', '9');
        self::assertSame([10050, '2026-11-22'], [$result['event']['amount_cents'], $result['event']['occurred_on']]);
    }

    public function testQueryWithoutOurNumberOrUnknownChargeStaysPending(): void
    {
        self::assertSame('pending', $this->adapter->getCharge('0000000001', null)['status']);
        self::assertSame([], $this->http->requests);
        $missing = $this->adapter->getCharge('0000000001', '999');
        self::assertSame(['pending', 'CondoConta não encontrou a cobrança.'], [$missing['status'], $missing['error']]);
    }

    public function testBankRejectionAndServerErrorAreDistinguished(): void
    {
        $this->http->nextResponse = ['status' => 422, 'body' => '{"detail":[{"loc":["body","payer","zipcode"],"msg":"invalid zipcode","type":"value_error"}]}'];
        $rejected = $this->adapter->registerCharge($this->charge());
        self::assertSame('rejected', $rejected['status']);
        self::assertStringContainsString('payer.zipcode invalid zipcode', (string)$rejected['error']);
        $this->http->nextResponse = ['status' => 400, 'body' => '{"detail":"Payer document invalid"}'];
        self::assertStringContainsString('Payer document invalid', (string)$this->adapter->registerCharge($this->charge())['error']);
        $this->http->nextResponse = ['status' => 500, 'body' => '{}'];
        $this->expectException(RuntimeException::class);
        $this->adapter->registerCharge($this->charge());
    }

    public function testRateLimitWaitsAndRetriesThenGivesUp(): void
    {
        $this->http->rateLimited = 2;
        self::assertSame('registered', $this->adapter->registerCharge($this->charge())['status']);
        self::assertSame([3, 8], $this->waits);
        $this->http->rateLimited = 10;
        try {
            $this->adapter->getCharge('0000000001', '5001');
            self::fail('deveria desistir depois de esperar');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('limitou', $error->getMessage());
        }
    }

    public function testExpiredTokenIsRenewedOnceAndAfterItsLifetime(): void
    {
        $this->adapter->registerCharge($this->charge());
        $this->http->expireToken = 1;
        self::assertSame('registered', $this->adapter->getCharge('0000000001', '5001')['status']);
        self::assertSame(2, $this->http->tokensIssued);
        $this->now += 3600;
        $this->adapter->getCharge('0000000001', '5001');
        self::assertSame(3, $this->http->tokensIssued, 'renova antes de vencer');
    }

    public function testMapChargeRefusesAnotherCharge(): void
    {
        $this->expectException(RuntimeException::class);
        CondoContaBankAdapter::mapCharge(['your_number' => '77', 'status' => 'CREATED', 'digitable_line' => '1'], '0000000001', '5');
    }

    public function testMapChargeErrorAndUnknownStatus(): void
    {
        self::assertSame('rejected', CondoContaBankAdapter::mapCharge(['status' => 'ERROR'], '1', '5')['status']);
        $result = CondoContaBankAdapter::mapCharge(['status' => 'PROTESTED', 'digitable_line' => '1'], '1', '5');
        self::assertSame('pending', $result['status']);
        self::assertStringContainsString('desconhecida', (string)$result['error']);
        self::assertNull($result['event']);
        $noLine = CondoContaBankAdapter::mapCharge(['status' => 'REGISTERED'], '1', '5');
        self::assertSame('pending', $noLine['status']);
    }

    public function testPdfComesFromTheOwnDomainOnly(): void
    {
        $this->adapter->registerCharge($this->charge());
        self::assertStringStartsWith('%PDF', $this->adapter->downloadBoleto('0000000001', '5001'));
        self::assertSame('https://files.condoconta.net/slip/5001.pdf', end($this->http->requests)['url']);
        $this->http->slips['5001']['pdf'] = 'https://condoconta.net.atacante.test/5001.pdf';
        $this->expectException(RuntimeException::class);
        $this->adapter->downloadBoleto('0000000001', '5001');
    }

    public function testWebhookOnlyReturnsCitedOurNumbers(): void
    {
        $found = $this->adapter->parseWebhook('{"event":"slip.paid","data":{"our_number":"5001","your_number":"0000000001","status":"PAID"}}');
        self::assertSame([['external_id' => '5001', 'our_number' => '0000000001']], $found);
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->parseWebhook('{"event":"ping"}');
    }

    public function testStatementUsesDaySheetAndBuildsStableFitids(): void
    {
        $this->http->sheet = [
            ['date' => '2026-11-20', 'balance' => 1000.0, 'transactions' => [
                ['transaction_code' => 'TX1', 'operation' => 'CASH_IN', 'value' => 150.25, 'description' => 'Liquidação de boleto', 'origin_name' => 'Pessoa Teste'],
                ['transaction_code' => 'TX1', 'operation' => 'CASH_IN', 'value' => 150.25, 'description' => 'Liquidação de boleto'],
                ['operation' => 'CASH_OUT', 'value' => 20, 'description' => 'Tarifa'],
                ['operation' => 'BLOCK', 'value' => 5],
            ]],
            ['date' => '2026-11-21', 'balance' => '980.00', 'transactions' => [
                ['transaction_code' => 'TX2', 'date' => '2026-11-21T08:00:00', 'operation' => 'CASH_OUT', 'value' => '10.00', 'description' => 'Pix enviado', 'destination_name' => 'Fornecedor'],
            ]],
        ];
        $lines = $this->adapter->statement('2026-11-20', '2026-11-21');
        self::assertCount(4, $lines);
        self::assertSame([15025, 15025, -2000, -1000], array_column($lines, 'amount_cents'));
        self::assertSame(['condoconta-TX1', 'condoconta-TX1-2'], [$lines[0]['fitid'], $lines[1]['fitid']]);
        self::assertSame(['2026-11-20', '2026-11-20', '2026-11-20', '2026-11-21'], array_column($lines, 'posted_on'));
        self::assertSame(['Liquidação de boleto - Pessoa Teste', 'Pix enviado - Fornecedor'], [$lines[0]['memo'], $lines[3]['memo']]);
        self::assertStringStartsWith('condoconta-h', $lines[2]['fitid']);
        self::assertSame(['2026-11-20', '2026-11-21'], [end($this->http->requests)['query']['initial_date'], end($this->http->requests)['query']['final_date']]);
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->statement('2026-11-21', '2026-11-20');
    }

    public function testBalanceTodayAndPastDayInCents(): void
    {
        self::assertSame(123456, $this->adapter->balance('2026-11-23'));
        $this->http->sheet = [['date' => '2026-11-21', 'balance' => '-15.50', 'transactions' => []]];
        self::assertSame(-1550, $this->adapter->balance('2026-11-21'));
        self::assertNull($this->adapter->balance('2026-11-19'));
    }

    public function testAuthenticationCheckAndRefusal(): void
    {
        $this->adapter->checkAuthentication();
        self::assertSame(1, $this->http->tokensIssued);
        try {
            $this->adapter(['client_secret' => 'errado'])->checkAuthentication();
            self::fail('deveria recusar');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('recusou a autenticação', $error->getMessage());
            self::assertStringContainsString('client ID ou o client secret', BankCredentialSetup::diagnose($error->getMessage(), 'condoconta'));
        }
    }

    public function testSimulationIsNotAvailableAndNoReturnFile(): void
    {
        try {
            $this->adapter->simulatePayment('5001');
            self::fail('a CondoConta não simula pagamento');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('não simula', $error->getMessage());
        }
        self::assertSame([], $this->http->requests);
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->parseReturn('qualquer');
    }

    public function testOnlySandboxAndCompleteCredentialsAreAccepted(): void
    {
        try {
            new CondoContaBankAdapter(['client_id' => 'a', 'client_secret' => 'b'], $this->http, CondoContaBankAdapter::PRODUCTION_URL);
            self::fail('produção não está liberada');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('sandbox', $error->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->adapter(['client_secret' => ' ']);
    }

    public function testCatalogKnowsCondoConta(): void
    {
        self::assertContains('condoconta', BankAdapters::NAMES);
        self::assertContains('condoconta', BankAdapters::REAL);
        self::assertSame(CondoContaBankAdapter::BANK_CODE, BankCredentials::ADAPTERS['condoconta']);
        self::assertSame('condoconta', BankCredentialSetup::bankOf(CondoContaBankAdapter::BANK_CODE));
        self::assertContains('condoconta', BankCredentials::WITHOUT_CERTIFICATE);
        self::assertNotContains('condoconta', BankCredentials::WITHOUT_SECRET);
        self::assertNotContains('condoconta', BankCredentials::NEEDS_CLIENT_NUMBER);
        self::assertArrayHasKey('condoconta', BankRemittances::MODES);
        self::assertCount(count(BankCredentials::ADAPTERS), array_unique(BankCredentials::ADAPTERS), 'um código por banco');
        $this->expectException(InvalidArgumentException::class);
        BankAdapters::get('condoconta');
    }
}
