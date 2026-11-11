<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use MovesOSTests\Fakes\FakeInterHttp;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankVault;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;

/** Adaptador do Inter com transporte falso: nada sai da máquina (ADR 0156). */
final class InterBankAdapterTest extends TestCase
{
    private FakeInterHttp $http;
    private InterBankAdapter $adapter;

    protected function setUp(): void
    {
        $this->http = new FakeInterHttp();
        $this->adapter = new InterBankAdapter(['client_id' => 'cid-teste', 'client_secret' => 'segredo-teste', 'certificate' => 'CERT', 'private_key' => 'KEY',
            'account_number' => '1234567-8'], $this->http);
    }

    private function charge(array $changes = []): array
    {
        return array_replace([
            'our_number' => '0000000001', 'amount_cents' => 50000, 'due_on' => '2026-11-20', 'description' => 'Taxa condominial 2026-11',
            'bank_code' => '077', 'branch' => '0001', 'account_number' => '1234567-8', 'wallet' => null, 'fine_bp' => 200, 'monthly_interest_bp' => 100,
            'payer' => ['name' => 'Pessoa Teste', 'document' => '111.444.777-35', 'email' => 'pessoa@example.test', 'street' => 'Rua Exemplo',
                'number' => '10', 'complement' => 'A-101', 'district' => 'Centro', 'city' => 'Cidade Teste', 'state' => 'sp', 'zip_code' => '01001-000'],
        ], $changes);
    }

    public function testChargeBodyFollowsInterFieldsWithFineAndInterest(): void
    {
        $body = InterBankAdapter::chargeBody($this->charge());
        self::assertSame('0000000001', $body['seuNumero']);
        self::assertSame(500.0, $body['valorNominal']);
        self::assertSame('2026-11-20', $body['dataVencimento']);
        self::assertSame(['cpfCnpj' => '11144477735', 'tipoPessoa' => 'FISICA', 'uf' => 'SP', 'cep' => '01001000'],
            array_intersect_key($body['pagador'], array_flip(['cpfCnpj', 'tipoPessoa', 'uf', 'cep'])));
        self::assertSame(['codigo' => 'PERCENTUAL', 'taxa' => 2.0], $body['multa']);
        self::assertSame(['codigo' => 'TAXAMENSAL', 'taxa' => 1.0], $body['mora']);
        self::assertSame('JURIDICA', InterBankAdapter::chargeBody($this->charge(['payer' => ['document' => '11222333000181'] + $this->charge()['payer']]))['pagador']['tipoPessoa']);
        self::assertArrayNotHasKey('multa', InterBankAdapter::chargeBody($this->charge(['fine_bp' => 0])));
    }

    public function testMissingPayerDataIsRejectedWithoutCallingTheBank(): void
    {
        foreach ([['payer' => null], ['payer' => ['document' => '123'] + $this->charge()['payer']], ['payer' => ['zip_code' => ''] + $this->charge()['payer']], ['amount_cents' => 100]] as $bad) {
            $result = $this->adapter->registerCharge($this->charge($bad));
            self::assertSame('rejected', $result['status']);
            self::assertNotEmpty($result['error']);
        }
        self::assertSame([], $this->http->requests, 'Nenhum pedido ao banco com dado faltando.');
    }

    public function testRegisterAuthenticatesOnceWithMtlsAndReturnsBoletoAndPix(): void
    {
        $first = $this->adapter->registerCharge($this->charge());
        self::assertSame('registered', $first['status']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', (string)$first['external_id']);
        self::assertNotNull($first['digitable_line']);
        self::assertNotNull($first['pix_copy_paste']);
        self::assertNotNull($first['bank_our_number']);
        $this->adapter->registerCharge($this->charge(['our_number' => '0000000002']));
        self::assertSame(1, $this->http->count('POST', '/oauth/v2/token'), 'Token reaproveitado (o Inter limita 5 tokens por minuto).');
        $token = $this->http->requests[0];
        self::assertStringContainsString('grant_type=client_credentials', (string)$token['body']);
        self::assertStringContainsString('boleto-cobranca.write', urldecode((string)$token['body']));
        self::assertSame('12345678', $this->http->requests[1]['headers']['x-conta-corrente']);
    }

    public function testTokenIsRenewedBeforeExpiring(): void
    {
        $now = 1_000_000;
        $adapter = new InterBankAdapter(['client_id' => 'a', 'client_secret' => 'b', 'certificate' => 'C', 'private_key' => 'K'], $this->http, InterBankAdapter::SANDBOX_URL,
            static function () use (&$now): int { return $now; });
        $adapter->checkAuthentication();
        $now += 3500;
        $adapter->checkAuthentication();
        self::assertSame(1, $this->http->count('POST', '/oauth/v2/token'));
        $now += 100;   // passou de 3540 s
        $adapter->checkAuthentication();
        self::assertSame(2, $this->http->count('POST', '/oauth/v2/token'));
    }

    public function testProcessingStaysPendingAndBankErrorsAreHandled(): void
    {
        $this->http->initialSituation = 'EM_PROCESSAMENTO';
        $pending = $this->adapter->registerCharge($this->charge());
        self::assertSame('pending', $pending['status']);
        self::assertNotNull($pending['external_id'], 'Pendente guarda o código para consultar depois.');

        $this->http->nextResponse = ['status' => 400, 'body' => json_encode(['title' => 'Requisição inválida', 'detail' => 'dataVencimento no passado'])];
        $rejected = $this->adapter->registerCharge($this->charge(['our_number' => '0000000003']));
        self::assertSame('rejected', $rejected['status']);
        self::assertStringContainsString('dataVencimento', (string)$rejected['error']);

        $this->http->nextResponse = ['status' => 503, 'body' => ''];
        try {
            $this->adapter->registerCharge($this->charge(['our_number' => '0000000004']));
            self::fail('5xx deve virar exceção (BankCharges deixa pendente).');
        } catch (RuntimeException) {
        }
        self::assertSame('pending', $this->adapter->getCharge('0000000005', null)['status'], 'Sem código não consulta e não reemite.');
    }

    public function testSituationMapping(): void
    {
        $base = ['boleto' => ['linhaDigitavel' => '0779', 'codigoBarras' => '0779', 'nossoNumero' => '1'], 'pix' => ['pixCopiaECola' => 'pix']];
        $map = static fn (string $situation, array $extra = []): array => InterBankAdapter::mapCharge(
            ['cobranca' => ['seuNumero' => '0000000001', 'situacao' => $situation, 'dataSituacao' => '2026-11-12'] + $extra] + $base, '0000000001', 'abc-12345678');
        self::assertSame('registered', $map('A_RECEBER')['status']);
        self::assertSame('registered', $map('ATRASADO')['status']);
        self::assertSame('pending', $map('EM_PROCESSAMENTO')['status']);
        self::assertSame('rejected', $map('FALHA_EMISSAO')['status']);
        $paid = $map('RECEBIDO', ['valorTotalRecebido' => '512.34']);
        self::assertSame(['paid', 51234, '2026-11-12', 'inter:abc-12345678:RECEBIDO'],
            [$paid['event']['kind'], $paid['event']['amount_cents'], $paid['event']['occurred_on'], $paid['event']['event_key']]);
        self::assertSame('cancelled', $map('CANCELADO')['event']['kind']);
        self::assertSame('cancelled', $map('EXPIRADO')['event']['kind']);
        $manual = $map('MARCADO_RECEBIDO');
        self::assertNull($manual['event'], 'Baixa manual no Inter não dá baixa automática aqui.');
        self::assertNotNull($manual['error']);
        self::assertSame('pending', $map('SITUACAO_NOVA')['status']);
        $this->expectException(RuntimeException::class);
        InterBankAdapter::mapCharge(['cobranca' => ['seuNumero' => '999', 'situacao' => 'RECEBIDO']], '0000000001', 'abc-12345678');
    }

    public function testVaultKeyReachesPhpFromDotEnv(): void
    {
        // O Apache do lab só recebe do .env as chaves listadas em Config.php (PR #147).
        $config = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Boot/Config.php');
        self::assertStringContainsString('"' . BankVault::ENV_KEY . '"', $config);
        self::assertStringContainsString('"BANK_WEBHOOK_BASE_URL"', $config);
    }

    public function testRemittanceStatusRule(): void
    {
        self::assertSame('confirmed', \Source\Services\Erp\Banking\BankRemittances::status(0, 0));
        self::assertSame('sent', \Source\Services\Erp\Banking\BankRemittances::status(2, 0));
        self::assertSame('rejected', \Source\Services\Erp\Banking\BankRemittances::status(2, 1));
    }

    public function testCentsWithoutFloatErrors(): void
    {
        self::assertSame(1999, InterBankAdapter::cents('19.99'));
        self::assertSame(1990, InterBankAdapter::cents('19.9'));
        self::assertSame(50000, InterBankAdapter::cents(500));
        self::assertSame(1999, InterBankAdapter::cents(19.99));
        self::assertNull(InterBankAdapter::cents('abc'));
        self::assertNull(InterBankAdapter::cents(null));
    }

    public function testCancelPdfAndNoReturnFile(): void
    {
        $id = (string)$this->adapter->registerCharge($this->charge())['external_id'];
        self::assertSame('cancelled', $this->adapter->cancelCharge('0000000001', $id)['status']);
        self::assertStringContainsString('motivoCancelamento', (string)end($this->http->requests)['body']);
        self::assertStringStartsWith('%PDF', $this->adapter->downloadBoleto('0000000001', $id));
        self::assertSame('pending', $this->adapter->cancelCharge('0000000001', null)['status']);
        $this->expectException(InvalidArgumentException::class);
        $this->adapter->parseReturn('qualquer coisa');
    }

    public function testWebhookBodyOnlyYieldsIdsAndRejectsGarbage(): void
    {
        $items = $this->adapter->parseWebhook(json_encode([
            ['codigoSolicitacao' => 'aaaaaaaa-0000-4000-8000-000000000001', 'seuNumero' => '0000000001', 'situacao' => 'RECEBIDO', 'valorTotalRecebido' => '9999.99'],
            ['codigoSolicitacao' => 'aaaaaaaa-0000-4000-8000-000000000001', 'situacao' => 'RECEBIDO'],
        ]));
        self::assertSame([['external_id' => 'aaaaaaaa-0000-4000-8000-000000000001', 'our_number' => '0000000001']], $items, 'Valor e situação do corpo são ignorados; repetido conta uma vez.');
        foreach (['', 'não é json', '[]', '[{"codigoSolicitacao":"../../x"}]', '[{"seuNumero":"1"}]'] as $bad) {
            try {
                $this->adapter->parseWebhook($bad);
                self::fail('Corpo malformado aceito: ' . $bad);
            } catch (InvalidArgumentException) {
            }
        }
    }

    public function testOnlySandboxAndCompleteCredentials(): void
    {
        try {
            new InterBankAdapter(['client_id' => 'a', 'client_secret' => 'b', 'certificate' => 'c', 'private_key' => 'd'], $this->http, 'https://cdpj.partners.bancointer.com.br');
            self::fail('Produção não pode ser usada nesta versão.');
        } catch (InvalidArgumentException) {
        }
        $this->expectException(InvalidArgumentException::class);
        new InterBankAdapter(['client_id' => 'a', 'client_secret' => '', 'certificate' => 'c', 'private_key' => 'd'], $this->http);
    }

    public function testVaultSealsWithContextAndCertificateCheck(): void
    {
        $vault = new BankVault(base64_encode(str_repeat('k', 32)));
        $sealed = $vault->seal(['client_secret' => 'segredo'], 'conta:1');
        self::assertStringNotContainsString('segredo', $sealed);
        self::assertSame(['client_secret' => 'segredo'], $vault->open($sealed, 'conta:1'));
        try {
            $vault->open($sealed, 'conta:2');
            self::fail('Segredo copiado para outra conta não pode abrir.');
        } catch (RuntimeException) {
        }

        [$cert, $key] = FakeInterHttp::certificatePair();
        $info = BankCredentials::inspectCertificate($cert, $key);
        self::assertSame('Teste Connect Sandbox', $info['subject']);
        [, $otherKey] = FakeInterHttp::certificatePair();
        $this->expectException(InvalidArgumentException::class);
        BankCredentials::inspectCertificate($cert, $otherKey);
    }
}
