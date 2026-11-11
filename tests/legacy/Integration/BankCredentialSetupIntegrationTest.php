<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use DomainException;
use InvalidArgumentException;
use MovesOSTests\Fakes\FakeInterHttp;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankVault;
use Source\Services\Erp\Registry\RegistryNotFound;

/** Credencial do Inter pela tela (ADR 0158): pacote ZIP, teste antes de gravar, escopo, diagnóstico e limite. */
final class BankCredentialSetupIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $interAccount;
    private int $otherBankAccount;
    private int $betaAccount;
    private User $user;
    private FakeInterHttp $http;
    private BankCredentialSetup $setup;
    private string $zip;

    protected function setUp(): void
    {
        parent::setUp();
        putenv(BankVault::ENV_KEY . '=' . base64_encode(str_repeat('v', 32)));
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare("INSERT INTO condominiums (name, status) VALUES (?, 'active')")->execute([$name]);
            $name === 'Alpha' ? $this->alpha = (int)$this->pdo->lastInsertId() : $this->beta = (int)$this->pdo->lastInsertId();
        }
        $this->interAccount = $this->account($this->alpha, '077');
        $this->otherBankAccount = $this->account($this->alpha, '341');
        $this->betaAccount = $this->account($this->beta, '077');
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->alpha);
        $this->user = (new User())->findById($id);
        $this->http = new FakeInterHttp();
        $this->setup = new BankCredentialSetup($this->pdo, new BankVault(), fn () => $this->http);
        [$cert, $key] = FakeInterHttp::certificatePair();
        $this->zip = base64_encode(FakeInterHttp::zip(['Inter API_Certificado.crt' => $cert, 'Inter API_Chave.key' => $key]));
    }

    protected function tearDown(): void
    {
        putenv(BankVault::ENV_KEY);
        parent::tearDown();
    }

    private function account(int $condominium, string $bank): int
    {
        $this->pdo->prepare("INSERT INTO erp_bank_accounts (condominium_id, name, bank_code, branch, account_number, kind) VALUES (?, ?, ?, '0001', '1234567-8', 'corrente')")
            ->execute([$condominium, 'Conta ' . $bank, $bank]);
        return (int)$this->pdo->lastInsertId();
    }

    private function input(array $changes = []): array
    {
        return array_replace(['client_id' => 'cid-teste-0001', 'client_secret' => 'segredo-teste', 'files' => [['name' => 'pacote.zip', 'content' => $this->zip]]], $changes);
    }

    public function testZipIsTestedSealedAndAccountSwitchesToInter(): void
    {
        $result = $this->setup->connect($this->user, $this->interAccount, $this->input(), 'https://erp.exemplo.test/');
        self::assertTrue($result['connected']);
        self::assertMatchesRegularExpression('#^https://erp\.exemplo\.test/api/v1/webhooks/bank/inter/[a-f0-9]{48}$#', $result['webhook_url']);
        self::assertSame('…0001', $result['credential']['client_id_hint']);
        $json = json_encode($result);
        self::assertStringNotContainsString('segredo-teste', $json, 'Secret nunca volta na resposta.');
        self::assertStringNotContainsString('PRIVATE KEY', $json);
        self::assertSame('/oauth/v2/token', $this->http->requests[0]['path'], 'Só pede token: nada é emitido no teste.');
        self::assertCount(1, $this->http->requests);

        self::assertSame('inter', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->interAccount)->fetchColumn());
        $sealed = (string)$this->pdo->query('SELECT secrets FROM erp_bank_credentials WHERE bank_account_id = ' . $this->interAccount)->fetchColumn();
        self::assertStringNotContainsString('segredo-teste', $sealed);
        self::assertSame('segredo-teste', (new BankCredentials($this->pdo, new BankVault()))->secrets($this->interAccount)['client_secret']);
        $audit = $this->pdo->query("SELECT context_json FROM system_audit_logs WHERE entity = 'erp_bank_credentials' ORDER BY id DESC LIMIT 1")->fetchColumn();
        self::assertStringContainsString('"after":"web"', (string)$audit);
        self::assertStringNotContainsString('segredo', (string)$audit);

        $accounts = $this->setup->accounts($this->user);
        self::assertSame([$this->interAccount], array_column($accounts, 'id'), 'Só contas 077 do escopo.');
        self::assertSame('inter', $accounts[0]['adapter']);
        self::assertTrue($this->setup->test($this->user, $this->interAccount)['connected']);
    }

    public function testProductionCertificateIsDiagnosedAndNothingIsSaved(): void
    {
        $this->http->tokenError = 'TLS connect error: error:0A000418:SSL routines::tlsv1 alert unknown ca';
        try {
            $this->setup->connect($this->user, $this->interAccount, $this->input(), 'https://h');
            self::fail('Teste com falha não pode gravar.');
        } catch (DomainException $e) {
            self::assertStringContainsString('produção', $e->getMessage());
            self::assertStringContainsString('Nada foi gravado', $e->getMessage());
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_bank_credentials')->fetchColumn());
        self::assertSame('sandbox', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->interAccount)->fetchColumn());

        $forced = $this->setup->connect($this->user, $this->interAccount, $this->input(['save_anyway' => true]), 'https://h');
        self::assertFalse($forced['connected']);
        self::assertStringContainsString('gravada mesmo assim', $forced['message']);
        self::assertFalse($this->setup->test($this->user, $this->interAccount)['connected']);
    }

    public function testScopeBankAndInputAreChecked(): void
    {
        try {
            $this->setup->connect($this->user, $this->betaAccount, $this->input(), 'https://h');
            self::fail('Conta de outro condomínio = não encontrada.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        try {
            $this->setup->test($this->user, $this->betaAccount);
            self::fail('Teste fora do escopo = não encontrada.');
        } catch (RegistryNotFound) {
            self::assertTrue(true);
        }
        try {
            $this->setup->connect($this->user, $this->otherBankAccount, $this->input(), 'https://h');
            self::fail('Só banco 077.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('077', $e->getMessage());
        }
        try {
            $this->setup->connect($this->user, $this->interAccount, $this->input(['client_secret' => '  ']), 'https://h');
            self::fail('Secret obrigatório.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('client secret', $e->getMessage());
        }
        [$otherCert] = FakeInterHttp::certificatePair();
        [, $otherKey] = FakeInterHttp::certificatePair();
        try {
            $this->setup->connect($this->user, $this->interAccount, $this->input(['files' => [['name' => 'p.zip', 'content' => base64_encode(FakeInterHttp::zip(['a.crt' => $otherCert, 'b.key' => $otherKey]))]]]), 'https://h');
            self::fail('Par que não combina.');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('não pertence', $e->getMessage());
        }
        self::assertSame([], $this->http->requests, 'Nada chega ao banco quando a entrada é inválida.');
        $this->expectException(DomainException::class);
        $this->setup->test($this->user, $this->interAccount);
    }

    public function testAttemptsAreLimitedPerUser(): void
    {
        for ($i = 0; $i < BankCredentialSetup::THROTTLE_MAX; $i++) {
            try {
                $this->setup->connect($this->user, $this->interAccount, $this->input(['client_secret' => '']), 'https://h');
            } catch (InvalidArgumentException) {
            }
        }
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Muitas tentativas');
        $this->setup->connect($this->user, $this->interAccount, $this->input(), 'https://h');
    }

    public function testRemoveReturnsAccountToInternalSandbox(): void
    {
        $this->setup->connect($this->user, $this->interAccount, $this->input(), 'https://h');
        $this->setup->remove($this->user, $this->interAccount);
        self::assertSame('sandbox', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->interAccount)->fetchColumn());
        self::assertNull($this->setup->accounts($this->user)[0]['credential']);
    }
}
