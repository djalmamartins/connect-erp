<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\Fakes\FakeInterHttp;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Banking\BankAdapters;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankCredentials;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\BankVault;
use Source\Services\Erp\Banking\BankWebhooks;
use Source\Services\Erp\Banking\Inter\InterBankAdapter;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Inter (ADR 0156) de ponta a ponta com o banco falso: emissão com Pix, webhook idempotente com
 * consulta na notificação, fora de ordem, forjado, divergência de valor, cancelamento e cofre.
 */
final class InterBankingIntegrationTest extends TestCase
{
    private int $alpha;
    private User $admin;
    private RegistryService $service;
    private BankCharges $charges;
    private BankVault $vault;
    private FakeInterHttp $http;
    private int $bank;
    private string $webhookSecret;
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
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Cobrança Inter', 'bank_code' => '077', 'branch' => '0001', 'account_number' => '1234567-8', 'kind' => 'corrente'])['id'];
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type, 'competence' => '2026-11', 'due_on' => '2026-11-20'])['id']);
        $this->entries = array_column($invoice['items'], 'entry_id');
        // Responsável financeiro sintético só da primeira unidade (CPF de teste válido).
        $unit = (int)$this->pdo->query('SELECT unit_id FROM erp_entries WHERE id = ' . (int)$this->entries[0])->fetchColumn();
        $this->pdo->prepare("INSERT INTO erp_residents (condominium_id, unit_id, unit_label, relationship, name, document, email) VALUES (?, ?, '101', 'financial', 'Pessoa Teste', '11144477735', 'pessoa@example.test')")
            ->execute([$this->alpha, $unit]);

        [$cert, $key] = FakeInterHttp::certificatePair();
        $this->webhookSecret = (new BankCredentials($this->pdo, $this->vault))->save($this->bank, 'inter', 'sandbox',
            ['client_id' => 'cid-teste-0001', 'client_secret' => 'segredo-teste', 'certificate' => $cert, 'private_key' => $key])['webhook_secret'];
        $this->http = new FakeInterHttp();
        BankAdapters::override('inter', new InterBankAdapter((new BankCredentials($this->pdo, $this->vault))->secrets($this->bank), $this->http));
    }

    protected function tearDown(): void
    {
        BankAdapters::override('inter', null);
        putenv(BankVault::ENV_KEY);
        parent::tearDown();
    }

    public function testCredentialsAreSealedAndAccountSwitchesToInter(): void
    {
        $row = $this->pdo->query('SELECT * FROM erp_bank_credentials WHERE bank_account_id = ' . $this->bank)->fetch(\PDO::FETCH_ASSOC);
        self::assertStringNotContainsString('segredo-teste', (string)$row['secrets'], 'Segredo nunca em texto aberto no banco.');
        self::assertStringNotContainsString('BEGIN', (string)$row['secrets']);
        self::assertSame('…0001', $row['client_id_hint']);
        self::assertSame(hash('sha256', $this->webhookSecret), $row['webhook_token_hash']);
        self::assertSame('inter', $this->pdo->query('SELECT adapter FROM erp_bank_accounts WHERE id = ' . $this->bank)->fetchColumn());
        self::assertArrayNotHasKey('secrets', (array)(new BankCredentials($this->pdo, $this->vault))->status($this->bank));

        $this->expectException(\InvalidArgumentException::class);
        (new BankCredentials($this->pdo, $this->vault))->save($this->bank, 'inter', 'production', []);
    }

    public function testIssueWithPixThenWebhookSettlesOnceEvenRepeatedOrOutOfOrder(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame(['registered', 'inter'], [$charge['status'], $charge['adapter']]);
        self::assertNotNull($charge['pix_copy_paste'], 'Boleto híbrido traz o Pix copia e cola.');
        self::assertTrue($charge['pdf_available']);
        $sent = $this->http->charges[$charge['external_id']]['request'];
        self::assertSame(['11144477735', 'Rua Teste', '01001000', '101'], [$sent['pagador']['cpfCnpj'], $sent['pagador']['endereco'], $sent['pagador']['cep'], $sent['pagador']['complemento']]);
        self::assertSame(2.0, $sent['multa']['taxa'], 'Multa da régua de cobrança (2%).');
        self::assertStringStartsWith('%PDF', $this->charges->pdf($this->admin, $charge['id']));

        // Webhook forjado: diz "RECEBIDO" com outro valor, mas o banco ainda diz A_RECEBER. Nada muda.
        $body = json_encode([['codigoSolicitacao' => $charge['external_id'], 'seuNumero' => $charge['our_number'], 'situacao' => 'RECEBIDO', 'valorTotalRecebido' => '1.00']]);
        $webhooks = new BankWebhooks($this->pdo, $this->vault);
        self::assertSame(200, $webhooks->receive('inter', $this->webhookSecret, $body)['http']);
        self::assertSame('registered', $this->charges->find($this->admin, $charge['id'])['status']);

        // O banco confirma o pagamento; a notificação chega (e chega de novo, e uma velha chega depois).
        $this->http->set($charge['external_id'], 'RECEBIDO', '500.00');
        $paidBody = json_encode([['codigoSolicitacao' => $charge['external_id'], 'situacao' => 'RECEBIDO']]);
        self::assertSame('processed', $webhooks->receive('inter', $this->webhookSecret, $paidBody)['status']);
        self::assertSame('duplicate', $webhooks->receive('inter', $this->webhookSecret, $paidBody)['status']);
        $old = json_encode([['codigoSolicitacao' => $charge['external_id'], 'situacao' => 'A_RECEBER', 'x' => 1]]);
        self::assertSame(200, $webhooks->receive('inter', $this->webhookSecret, $old)['http']);

        $after = $this->charges->find($this->admin, $charge['id']);
        self::assertSame(['paid', 50000, '2026-11-12'], [$after['status'], $after['paid_cents'], $after['paid_on']]);
        self::assertSame('2026-11-12', $this->service->find($this->admin, 'entries', $this->entries[0])['paid_on'], 'Baixa na conta a receber.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_events WHERE charge_id = {$charge['id']} AND kind = 'paid'")->fetchColumn(), 'Um evento só.');
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_webhook_deliveries WHERE status = 'processed'")->fetchColumn());
        $payload = (string)$this->pdo->query('SELECT payload FROM erp_bank_webhook_deliveries ORDER BY id LIMIT 1')->fetchColumn();
        self::assertStringNotContainsString('codigoSolicitacao', $payload, 'Corpo guardado cifrado.');
        self::assertSame($body, $this->vault->openText($payload, 'erp_bank_webhook:' . $this->bank));
    }

    public function testWrongSecretGarbageAndUnknownChargesChangeNothing(): void
    {
        $webhooks = new BankWebhooks($this->pdo, $this->vault);
        self::assertSame(404, $webhooks->receive('inter', str_repeat('a', 48), '[]')['http']);
        self::assertSame(404, $webhooks->receive('inter', 'curto', '[]')['http']);
        self::assertSame(400, $webhooks->receive('inter', $this->webhookSecret, 'não é json')['http']);
        self::assertSame(413, $webhooks->receive('inter', $this->webhookSecret, '')['http']);
        $unknown = $webhooks->receive('inter', $this->webhookSecret, json_encode([['codigoSolicitacao' => 'ffffffff-0000-4000-8000-000000000999', 'situacao' => 'RECEBIDO']]));
        self::assertSame([200, 0], [$unknown['http'], $unknown['applied']]);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_bank_events')->fetchColumn());
    }

    public function testBankDownMakesDeliveryRetryableAndAmountDivergenceGoesToReview(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $this->http->set($charge['external_id'], 'RECEBIDO', '480.00');
        $body = json_encode([['codigoSolicitacao' => $charge['external_id'], 'situacao' => 'RECEBIDO']]);
        $webhooks = new BankWebhooks($this->pdo, $this->vault);
        $this->http->failNext = true;
        self::assertSame(503, $webhooks->receive('inter', $this->webhookSecret, $body)['http'], 'Sem resposta do banco: pede reenvio.');
        self::assertSame('processed', $webhooks->receive('inter', $this->webhookSecret, $body)['status'], 'O reenvio processa a mesma entrega.');

        self::assertSame('registered', $this->charges->find($this->admin, $charge['id'])['status'], 'Valor diferente não dá baixa.');
        $queue = (new BankReturns($this->pdo))->divergences($this->admin, $this->alpha);
        self::assertCount(1, $queue);
        self::assertStringContainsString('diferente', (string)$queue[0]['note']);
    }

    public function testPendingIssueConfirmsOnRefreshMissingPayerIsRejectedAndCancel(): void
    {
        $this->http->initialSituation = 'EM_PROCESSAMENTO';
        $pending = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        self::assertSame('pending', $pending['status']);
        $this->http->set($pending['external_id'], 'A_RECEBER');
        self::assertSame('registered', $this->charges->refresh($this->admin, $pending['id'])['status']);

        $cancelled = $this->charges->cancel($this->admin, $pending['id']);
        self::assertSame('cancelled', $cancelled['status']);
        self::assertSame('CANCELADO', $this->http->charges[$pending['external_id']]['situacao']);

        $noPayer = $this->charges->issue($this->admin, $this->entries[1], $this->bank);
        self::assertSame('rejected', $noPayer['status'], 'Unidade sem responsável com CPF/CNPJ não vai ao banco.');
        self::assertStringContainsString('CPF', (string)$noPayer['last_error']);
        $lots = (new \Source\Services\Erp\Banking\BankRemittances($this->pdo))->list($this->admin, $this->alpha);
        self::assertSame(1, $lots['total'], 'Um lote: conta Inter, hoje.');
        self::assertSame([2, 1, 1, 'rejected'], [$lots['data'][0]['charges_count'], $lots['data'][0]['rejected_count'], $lots['data'][0]['cancelled_count'], $lots['data'][0]['status']]);
        self::assertSame(0, (new \Source\Services\Erp\Banking\BankRemittances($this->pdo))->list((new User())->findById($this->createUser(['level' => 2])), null)['total'], 'Fora do escopo não vê.');
        self::assertSame(1, count(array_filter($this->http->requests, static fn (array $r): bool => $r['method'] === 'POST' && $r['path'] === '/cobranca/v3/cobrancas')), 'Só a primeira emissão chegou ao banco.');
    }

    public function testBankCancellationArrivesByWebhook(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $this->http->set($charge['external_id'], 'EXPIRADO');
        (new BankWebhooks($this->pdo, $this->vault))->receive('inter', $this->webhookSecret, json_encode([['codigoSolicitacao' => $charge['external_id']]]));
        self::assertSame('cancelled', $this->charges->find($this->admin, $charge['id'])['status']);
        self::assertNotSame($charge['id'], $this->charges->issue($this->admin, $this->entries[0], $this->bank)['id'], 'Expirado libera novo boleto.');
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
