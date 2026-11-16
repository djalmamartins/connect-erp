<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\FakeCoraHttp;
use MovesOSTests\Fakes\FakeInterHttp;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankCredentialSetup;
use Source\Services\Erp\Banking\BankVault;
use Source\Services\Erp\Banking\Cora\CoraBankAdapter;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Cora (ADR 0322) de ponta a ponta com o banco falso: credencial pela tela sem secret, emissão com Pix,
 * simulação de pagamento com baixa, consulta automática, cancelamento e extrato.
 */
final class CoraBankingIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private BankCharges $charges;
    private BankVault $vault;
    private FakeCoraHttp $http;
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
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Cobrança Cora', 'bank_code' => '403', 'branch' => '0001', 'account_number' => '7654321-0', 'kind' => 'corrente'])['id'];
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-11', 'due_on' => '2026-11-20'])['id']);
        $this->entries = array_column($invoice['items'], 'entry_id');
        foreach ($this->entries as $i => $entry) {
            $unit = (int)$this->pdo->query('SELECT unit_id FROM erp_entries WHERE id = ' . (int)$entry)->fetchColumn();
            $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email) VALUES (?, ?, ?, 'financial', ?, '11144477735', 'pessoa@example.test')")
                ->execute([$this->alpha, $unit, (string)(101 + $i), 'Pessoa Teste ' . ($i + 1)]);
        }
        $this->http = new FakeCoraHttp();
        // Credencial pela tela, como o Djalma vai fazer: client ID + certificado, sem secret.
        [$cert, $key] = FakeInterHttp::certificatePair();
        $setup = new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http);
        $result = $setup->connect($this->admin, $this->bank, ['client_id' => 'int-teste-0001', 'client_secret' => 'ignorado',
            'files' => [['name' => 'certificate.pem', 'content' => base64_encode($cert)], ['name' => 'private-key.key', 'content' => base64_encode($key)]]], 'https://erp.exemplo.test');
        self::assertTrue($result['connected'], $result['message']);
        BankAdapters::override('cora', new CoraBankAdapter((new BankCredentials($this->pdo, $this->vault))->secrets($this->bank)
            + ['account_number' => '7654321-0'], $this->http, CoraBankAdapter::STAGE_URL, null, static function (): void {
            }));
    }

    protected function tearDown(): void
    {
        BankAdapters::override('cora', null);
        putenv(BankVault::ENV_KEY);
        parent::tearDown();
    }

    public function testCredentialWithoutSecretSwitchesAccountToCora(): void
    {
        self::assertSame('cora', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->bank)->fetchColumn());
        $secrets = (new BankCredentials($this->pdo, $this->vault))->secrets($this->bank);
        self::assertSame(['int-teste-0001', ''], [$secrets['client_id'], $secrets['client_secret']], 'A Cora não usa secret: nada é guardado nele.');
        self::assertSame('/token', $this->http->requests[0]['path'], 'O teste só pede token.');
        $accounts = (new BankCredentialSetup($this->pdo, $this->vault, fn () => $this->http))->accounts($this->admin);
        self::assertSame([['cora', false]], array_map(static fn (array $a): array => [$a['bank'], $a['needs_secret']], $accounts));
    }

    public function testIssueSimulatePaymentAndSettle(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame(['registered', 'cora'], [$charge['status'], $charge['adapter']]);
        self::assertNotNull($charge['pix_copy_paste'], 'Boleto com Pix.');
        self::assertTrue($charge['pdf_available']);
        self::assertStringStartsWith('%PDF', $this->charges->pdf($this->admin, $charge['id']));
        $sent = $this->http->invoices[$charge['external_id']]['request'];
        self::assertSame([50000, '11144477735', ['rate' => 2.0]], [$sent['services'][0]['amount'], $sent['customer']['document']['identity'], $sent['payment_terms']['fine']]);

        $after = $this->charges->simulatePayment($this->admin, $charge['id']);
        self::assertSame(['paid', 50000, '2026-11-12'], [$after['status'], $after['paid_cents'], $after['paid_on']]);
        self::assertSame('2026-11-12', $this->service->find($this->admin, 'entries', $this->entries[0])['paid_on'], 'Baixa na conta a receber.');
    }

    public function testHourlySyncSettlesWithoutWebhookAndCancelWorks(): void
    {
        $first = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $second = $this->charges->issue($this->admin, $this->entries[1], $this->bank);
        $this->http->set($first['external_id'], 'PAID', '2026-11-19');
        $summary = $this->charges->syncOpen(50);
        self::assertSame(1, $summary['paid']);
        self::assertSame('paid', $this->charges->find($this->admin, $first['id'])['status']);

        $cancelled = $this->charges->cancel($this->admin, $second['id']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('CANCELLED', $this->http->invoices[$second['external_id']]['status']);
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
