<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\FakeCondoContaHttp;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankVault;
use Source\Services\Erp\Banking\CondoConta\CondoContaBankAdapter;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;

/**
 * CondoConta (ADR 0649) de ponta a ponta com o banco falso: credencial do sandbox pela tela (client ID e client secret,
 * sem certificado), emissão com Pix, baixa pela consulta automática, cancelamento, extrato e recusa da simulação.
 */
final class CondoContaBankingIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private BankCharges $charges;
    private BankVault $vault;
    private FakeCondoContaHttp $http;
    private int $bank;
    /** @var list<int> */
    private array $entries = [];

    protected function setUp(): void
    {
        parent::setUp();
        putenv(BankVault::ENV_KEY . '=' . base64_encode(str_repeat('v', 32)));
        $this->vault = new BankVault();
        $this->pdo->prepare("INSERT INTO condominiums (name, status, street, number, district, city, state, zip_code) VALUES ('Alpha', 'active', 'Rua Teste', '100', 'Centro', 'Cidade Teste', 'SP', '01001000')")->execute();
        $this->alpha = (int)$this->pdo->lastInsertId();
        $this->admin = $this->user([$this->alpha]);
        $this->service = new RegistryService($this->pdo);
        $this->charges = new BankCharges($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        foreach (['101', '102'] as $number) {
            $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento']);
        }
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Cobrança CondoConta', 'bank_code' => CondoContaBankAdapter::BANK_CODE, 'branch' => '0001', 'account_number' => '76543-2', 'kind' => 'corrente'])['id'];
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-11', 'due_on' => '2026-11-20'])['id']);
        $this->entries = array_column($invoice['items'], 'entry_id');
        foreach ($this->entries as $i => $entry) {
            $unit = (int)$this->pdo->query('SELECT unit_id FROM erp_entries WHERE id = ' . (int)$entry)->fetchColumn();
            $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email) VALUES (?, ?, ?, 'financial', ?, '11144477735', 'pessoa@example.test')")
                ->execute([$this->alpha, $unit, (string)(101 + $i), 'Pessoa Teste ' . ($i + 1)]);
        }
        $this->http = new FakeCondoContaHttp();
        // Credencial do sandbox pela tela, como o Djalma vai fazer: client ID e client secret, sem certificado.
        $setup = new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http);
        $result = $setup->connect($this->admin, $this->bank, ['client_id' => FakeCondoContaHttp::CLIENT_ID, 'client_secret' => FakeCondoContaHttp::CLIENT_SECRET,
            'files' => []], 'https://erp.exemplo.test');
        self::assertTrue($result['connected'], $result['message']);
        self::assertNull($result['certificate_expires_on']);
        self::assertStringContainsString('/api/v1/webhooks/bank/condoconta/', $result['webhook_url']);
        BankAdapters::override('condoconta', new CondoContaBankAdapter((new BankCredentials($this->pdo, $this->vault))->secrets($this->bank)
            + ['account_number' => '76543-2'], $this->http, CondoContaBankAdapter::SANDBOX_URL, null, static function (): void {
            }));
    }

    protected function tearDown(): void
    {
        BankAdapters::override('condoconta', null);
        putenv(BankVault::ENV_KEY);
        parent::tearDown();
    }

    public function testSandboxCredentialWithoutCertificateSwitchesAccountToCondoConta(): void
    {
        self::assertSame('condoconta', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->bank)->fetchColumn());
        $secrets = (new BankCredentials($this->pdo, $this->vault))->secrets($this->bank);
        self::assertSame([FakeCondoContaHttp::CLIENT_ID, FakeCondoContaHttp::CLIENT_SECRET, ''], [$secrets['client_id'], $secrets['client_secret'], $secrets['certificate']]);
        self::assertSame(['/token'], array_column($this->http->requests, 'path'), 'O teste só pede o token, não emite.');
        $status = (new BankCredentials($this->pdo, $this->vault))->status($this->bank);
        self::assertSame([null, null], [$status['certificate_subject'], $status['certificate_expires_on']]);
        $accounts = (new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http))->accounts($this->admin);
        self::assertSame([['condoconta', true, false, false]], array_map(static fn (array $a): array => [$a['bank'], $a['needs_secret'], $a['needs_certificate'], $a['needs_client_number']], $accounts));
        $terminal = (new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http))->testFromTerminal($this->bank);
        self::assertSame([true, 'condoconta'], [$terminal['connected'], $terminal['bank']]);
    }

    public function testSecretIsRequiredAndWrongSecretSavesNothing(): void
    {
        $setup = new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http);
        try {
            $setup->connect($this->admin, $this->bank, ['client_id' => 'x', 'files' => []], 'https://erp.exemplo.test');
            self::fail('sem client secret deveria recusar');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('client secret', $error->getMessage());
        }
        try {
            $setup->connect($this->admin, $this->bank, ['client_id' => FakeCondoContaHttp::CLIENT_ID, 'client_secret' => 'errado', 'files' => []], 'https://erp.exemplo.test');
            self::fail('secret errado deveria recusar');
        } catch (\DomainException $error) {
            self::assertStringContainsString('Nada foi gravado', $error->getMessage());
            self::assertStringContainsString('CondoConta recusou', $error->getMessage());
        }
        self::assertSame(FakeCondoContaHttp::CLIENT_SECRET, (new BankCredentials($this->pdo, $this->vault))->secrets($this->bank)['client_secret'], 'a credencial boa continua');
    }

    public function testIssueWithPixHourlySyncSettlesAndCancelWorks(): void
    {
        $first = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame(['registered', 'condoconta'], [$first['status'], $first['adapter']]);
        self::assertNotNull($first['pix_copy_paste'], 'Boleto com Pix.');
        self::assertTrue($first['pdf_available']);
        self::assertStringStartsWith('%PDF', $this->charges->pdf($this->admin, $first['id']));
        $posts = array_values(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/slip'));
        $sent = json_decode((string)$posts[0]['body'], true);
        self::assertSame(['500.00', '11144477735', 'CONDOMINIUM', '01001000'], [$sent['value'], $sent['payer']['document_number'], $sent['type'], $sent['payer']['zipcode']]);
        self::assertSame((string)$first['our_number'], $sent['your_number']);

        $second = $this->charges->issue($this->admin, $this->entries[1], $this->bank);
        $this->http->pay((string)$first['external_id'], '2026-11-19');
        $summary = $this->charges->syncOpen(50);
        self::assertSame(1, $summary['paid']);
        $paid = $this->charges->find($this->admin, $first['id']);
        self::assertSame(['paid', 50000, '2026-11-19'], [$paid['status'], $paid['paid_cents'], $paid['paid_on']]);
        self::assertSame('2026-11-19', $this->service->find($this->admin, 'entries', $this->entries[0])['paid_on'], 'Baixa na conta a receber.');

        self::assertSame('cancelled', $this->charges->cancel($this->admin, $second['id'])['status']);
        self::assertSame('CANCELED', $this->http->slips[(string)$second['external_id']]['status']);
    }

    public function testSimulatedPaymentIsRefusedForCondoConta(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $this->expectException(\InvalidArgumentException::class);
        $this->charges->simulatePayment($this->admin, $charge['id']);
    }

    private function user(array $condominiums): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        return (new User())->findById($id);
    }
}
