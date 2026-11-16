<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeCoraHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\Cora\CoraBankAdapter;

/** Adaptador da Cora com transporte falso: nada sai da máquina (ADR 0322). */
final class CoraBankAdapterTest extends TestCase
{
    private FakeCoraHttp $http;
    private CoraBankAdapter $adapter;
    /** @var list<int> */
    private array $waits = [];

    protected function setUp(): void
    {
        $this->http = new FakeCoraHttp();
        $this->waits = [];
        $this->adapter = new CoraBankAdapter(['client_id' => 'int-teste', 'certificate' => 'CERT', 'private_key' => 'KEY', 'account_number' => '1234567-8'],
            $this->http, CoraBankAdapter::STAGE_URL, null, function (int $s): void {
                $this->waits[] = $s;
            });
    }

    private function charge(array $changes = []): array
    {
        return array_replace([
            'our_number' => '0000000001', 'amount_cents' => 50000, 'due_on' => '2026-11-20', 'description' => 'Taxa condominial 2026-11',
            'bank_code' => '403', 'branch' => '0001', 'account_number' => '1234567-8', 'wallet' => null, 'fine_bp' => 200, 'monthly_interest_bp' => 100,
            'payer' => ['name' => 'Pessoa Teste', 'document' => '111.444.777-35', 'email' => 'pessoa@example.test', 'street' => 'Rua Exemplo',
                'number' => '10', 'complement' => 'A-101', 'district' => 'Centro', 'city' => 'Cidade Teste', 'state' => 'sp', 'zip_code' => '01001-000'],
        ], $changes);
    }

    public function testChargeBodyUsesCentsBoletoWithPixFineAndInterest(): void
    {
        $body = CoraBankAdapter::chargeBody($this->charge());
        self::assertSame('0000000001', $body['code']);
        self::assertSame(50000, $body['services'][0]['amount']);
        self::assertSame('2026-11-20', $body['payment_terms']['due_date']);
        self::assertSame(['rate' => 2.0], $body['payment_terms']['fine']);
        self::assertSame(['rate' => 1.0], $body['payment_terms']['interest']);
        self::assertSame(['BANK_SLIP', 'PIX'], $body['payment_forms']);
        self::assertSame(['identity' => '11144477735', 'type' => 'CPF'], $body['customer']['document']);
        self::assertSame(['SP', '01001000'], [$body['customer']['address']['state'], $body['customer']['address']['zip_code']]);
        self::assertSame('CNPJ', CoraBankAdapter::chargeBody($this->charge(['payer' => ['document' => '11222333000181'] + $this->charge()['payer']]))['customer']['document']['type']);
        self::assertArrayNotHasKey('fine', CoraBankAdapter::chargeBody($this->charge(['fine_bp' => 0]))['payment_terms']);
        self::assertSame('S/N', CoraBankAdapter::chargeBody($this->charge(['payer' => ['number' => ''] + $this->charge()['payer']]))['customer']['address']['number']);
    }

    public function testMissingPayerDataOrTooSmallAmountIsRejectedWithoutCallingTheBank(): void
    {
        foreach ([['payer' => null], ['payer' => ['document' => '123'] + $this->charge()['payer']], ['payer' => ['district' => ''] + $this->charge()['payer']],
            ['amount_cents' => 499]] as $bad) {
            $result = $this->adapter->registerCharge($this->charge($bad));
            self::assertSame('rejected', $result['status']);
            self::assertNotEmpty($result['error']);
        }
        self::assertSame([], $this->http->requests, 'Nenhum pedido ao banco com dado faltando.');
    }

    public function testRegisterIsSynchronousWithLinePixAndStableIdempotencyKey(): void
    {
        $result = $this->adapter->registerCharge($this->charge());
        self::assertSame('registered', $result['status']);
        self::assertStringStartsWith('inv_', (string)$result['external_id']);
        self::assertNotEmpty($result['digitable_line']);
        self::assertStringStartsWith('00020101', (string)$result['pix_copy_paste']);
        $post = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/v2/invoices/'))[0];
        self::assertSame(CoraBankAdapter::idempotencyKey('1234567-8', '0000000001'), $post['headers']['Idempotency-Key']);
        self::assertStringStartsWith(CoraBankAdapter::STAGE_URL, $post['url']);
        // Pedir de novo o mesmo nosso número devolve o mesmo boleto (sem cobrança em dobro).
        $again = $this->adapter->registerCharge($this->charge());
        self::assertSame($result['external_id'], $again['external_id']);
        self::assertCount(1, $this->http->invoices);
        self::assertNotSame(CoraBankAdapter::idempotencyKey('1234567-8', '0000000001'), CoraBankAdapter::idempotencyKey('1234567-8', '0000000002'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-5[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', CoraBankAdapter::idempotencyKey('1', '2'));
    }

    public function testTokenUsesClientIdOnlyAndIsReused(): void
    {
        $this->adapter->registerCharge($this->charge());
        $this->adapter->getCharge('0000000001', array_key_first($this->http->invoices));
        self::assertSame(1, $this->http->count('POST', '/token'));
        parse_str((string)$this->http->requests[0]['body'], $form);
        self::assertSame(['grant_type' => 'client_credentials', 'client_id' => 'int-teste'], $form);
    }

    public function testStatusMapping(): void
    {
        $map = static fn (string $status, array $extra = []): array => CoraBankAdapter::mapCharge(
            ['id' => 'inv_abc123456', 'code' => '0000000001', 'status' => $status, 'payment_options' => ['bank_slip' => ['digitable' => '4039']]] + $extra,
            '0000000001', 'inv_abc123456');
        self::assertSame('registered', $map('OPEN')['status']);
        self::assertSame('registered', $map('LATE')['status']);
        self::assertNull($map('IN_PAYMENT')['event']);
        self::assertSame('pending', $map('DRAFT')['status']);
        $paid = $map('PAID', ['total_paid' => 50210, 'occurrence_date' => '2026-11-21']);
        self::assertSame(['paid', 50210, '2026-11-21'], [$paid['event']['kind'], $paid['event']['amount_cents'], $paid['event']['occurred_on']]);
        self::assertSame('cancelled', $map('CANCELLED')['event']['kind']);
        $unknown = $map('ALGO_NOVO');
        self::assertSame(['pending', null], [$unknown['status'], $unknown['event']]);
        self::assertStringContainsString('desconhecida', (string)$unknown['error']);
    }

    public function testOtherInvoiceForTheSameIdIsRefused(): void
    {
        $this->expectException(RuntimeException::class);
        CoraBankAdapter::mapCharge(['code' => '999', 'status' => 'PAID'], '0000000001', 'inv_abc123456');
    }

    public function testCancelSimulateAndPaidCancelRefusal(): void
    {
        $id = (string)$this->adapter->registerCharge($this->charge())['external_id'];
        $this->adapter->simulatePayment($id);
        $paid = $this->adapter->getCharge('0000000001', $id);
        self::assertSame('paid', $paid['event']['kind']);
        self::assertSame(50000, $paid['event']['amount_cents']);
        $refused = $this->adapter->cancelCharge('0000000001', $id);
        self::assertSame('rejected', $refused['status']);
        self::assertStringContainsString('já foi pago', (string)$refused['error']);

        $other = (string)$this->adapter->registerCharge($this->charge(['our_number' => '0000000002']))['external_id'];
        self::assertSame('cancelled', $this->adapter->cancelCharge('0000000002', $other)['status']);
        self::assertSame('cancelled', $this->adapter->getCharge('0000000002', $other)['event']['kind']);
    }

    public function testRateLimitWaitsAndRetries(): void
    {
        $this->http->rateLimited = 2;
        self::assertSame('registered', $this->adapter->registerCharge($this->charge())['status']);
        self::assertSame([3, 8], $this->waits);
        $this->http->rateLimited = 10;
        $this->expectException(RuntimeException::class);
        $this->adapter->getCharge('0000000001', array_key_first($this->http->invoices));
    }

    public function testStatementBecomesOfxLinesAndBalanceIsCents(): void
    {
        $this->http->entries = [
            ['id' => 'ent_1', 'type' => 'CREDIT', 'amount' => 50000, 'createdAt' => '2026-11-03T10:00:00Z', 'transaction' => ['description' => 'Boleto recebido', 'counterParty' => ['name' => 'Pessoa Teste']]],
            ['id' => 'ent_2', 'type' => 'DEBIT', 'amount' => 350, 'createdAt' => '2026-11-04T10:00:00Z', 'transaction' => ['type' => 'FEE', 'description' => 'Tarifa']],
            ['id' => 'ent_3', 'type' => 'BLOCK', 'amount' => 100, 'createdAt' => '2026-11-04T10:00:00Z'],
        ];
        $lines = $this->adapter->statement('2026-11-01', '2026-11-30');
        self::assertCount(2, $lines);
        self::assertSame(['cora-ent_1', 'credit', 50000], [$lines[0]['fitid'], $lines[0]['kind'], $lines[0]['amount_cents']]);
        self::assertSame(-350, $lines[1]['amount_cents']);
        self::assertSame(123456, $this->adapter->balance(date('Y-m-d')));
        self::assertNull($this->adapter->balance('2020-01-01'), 'A Cora só tem o saldo de agora.');
    }

    public function testPdfOnlyFromCoraDomain(): void
    {
        $id = (string)$this->adapter->registerCharge($this->charge())['external_id'];
        self::assertStringStartsWith('%PDF', $this->adapter->downloadBoleto('0000000001', $id));
        $this->http->pdfUrl = 'https://outro-lugar.example.com/boleto.pdf';
        $this->expectException(RuntimeException::class);
        $this->adapter->downloadBoleto('0000000001', $id);
    }

    public function testOnlyStageAndCompleteCredentialsAreAccepted(): void
    {
        try {
            new CoraBankAdapter(['client_id' => 'a', 'certificate' => 'c', 'private_key' => 'd'], $this->http, CoraBankAdapter::PRODUCTION_URL);
            self::fail('Produção precisa de aprovação.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('ambiente de teste', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        new CoraBankAdapter(['client_id' => '', 'certificate' => 'c', 'private_key' => 'd'], $this->http);
    }

    public function testCatalogAndCredentialRulesKnowCora(): void
    {
        self::assertContains('cora', BankAdapters::NAMES);
        self::assertContains('cora', BankAdapters::REAL);
        self::assertSame('403', BankCredentials::ADAPTERS['cora']);
        self::assertSame('cora', BankCredentialSetup::bankOf('403'));
        self::assertSame('inter', BankCredentialSetup::bankOf('077'));
        self::assertStringContainsString('Ambiente de teste', BankCredentialSetup::diagnose('Sem resposta do banco: tlsv1 alert unknown ca', 'cora'));
        self::assertStringContainsString('client ID', BankCredentialSetup::diagnose('Cora recusou a autenticação (401).', 'cora'));
        $this->expectException(InvalidArgumentException::class);
        BankAdapters::get('cora');
    }
}
