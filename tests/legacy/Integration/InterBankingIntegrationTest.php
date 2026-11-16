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

    public function testSandboxSimulatedPaymentSettlesThroughRefresh(): void
    {
        $charge = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $after = $this->charges->simulatePayment($this->admin, $charge['id'], 'BOLETO');
        self::assertSame(['paid', 50000], [$after['status'], $after['paid_cents']]);
        $pay = array_values(array_filter($this->http->requests, static fn (array $r): bool => str_ends_with($r['path'], '/pagar')));
        self::assertCount(1, $pay);
        self::assertSame(['pagarCom' => 'BOLETO'], json_decode((string)$pay[0]['body'], true));
        self::assertSame('2026-11-12', $this->service->find($this->admin, 'entries', $this->entries[0])['paid_on'], 'Baixa pela mesma conciliação.');
        try {
            $this->charges->simulatePayment($this->admin, $charge['id'], 'BOLETO');
            self::fail('Boleto pago não pode ser pago de novo.');
        } catch (\DomainException $e) {
            self::assertStringContainsString('registrado', $e->getMessage());
        }
    }

    public function testHourlySyncSettlesPaidAndCancelledWithoutWebhook(): void
    {
        $paid = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $this->http->set($paid['external_id'], 'RECEBIDO', '500.00');
        $pauses = 0;
        $summary = $this->charges->syncOpen(50, function () use (&$pauses): void {
            $pauses++;
        });
        self::assertSame(['checked' => 1, 'paid' => 1, 'cancelled' => 0, 'failed' => 0], $summary);
        self::assertSame('paid', $this->charges->find($this->admin, $paid['id'])['status']);
        self::assertSame('2026-11-12', $this->service->find($this->admin, 'entries', $this->entries[0])['paid_on']);
        self::assertSame(0, $pauses, 'Pausa só entre consultas.');
        self::assertSame(['checked' => 0, 'paid' => 0, 'cancelled' => 0, 'failed' => 0], $this->charges->syncOpen(), 'Pago não é consultado de novo.');

        // Banco fora do ar: conta como falha e não muda nada.
        $this->pdo->exec("UPDATE erp_bank_charges SET status = 'registered' WHERE id = " . (int)$paid['id']);
        $this->http->failNext = true;
        self::assertSame(1, $this->charges->syncOpen()['failed']);
    }

    public function testStatementFetchedFromInterGoesThroughTheOfxPreviewAndMatchesTheSettledBoleto(): void
    {
        $paid = $this->charges->issue($this->admin, $this->entries[0], $this->bank);
        $this->http->set($paid['external_id'], 'RECEBIDO', '500.00');
        $this->charges->syncOpen();
        $this->http->statement = [
            ['idTransacao' => 'TX-1', 'dataTransacao' => '2026-11-12', 'tipoOperacao' => 'C', 'valor' => '500.00', 'titulo' => 'Boleto recebido', 'descricao' => 'Pessoa Teste'],
            ['idTransacao' => 'TX-2', 'dataTransacao' => '2026-11-12', 'tipoOperacao' => 'D', 'valor' => '2.50', 'titulo' => 'Tarifa boleto', 'descricao' => ''],
        ];
        $this->http->balance = '497.50';
        $root = sys_get_temp_dir() . '/stmt-test-' . bin2hex(random_bytes(4));
        $fetch = new \Source\Services\Erp\Banking\BankStatementFetch($this->pdo, null, new \Source\Services\Erp\Imports\Imports($this->pdo, null, $root, true),
            static fn (): string => '2026-11-16');
        self::assertSame([$this->bank], array_column($fetch->accounts($this->admin, $this->alpha), 'id'), 'Conta do Inter com credencial busca o extrato.');

        $input = ['condominium_id' => $this->alpha, 'bank_account_id' => $this->bank, 'from' => '2026-11-01', 'to' => '2026-11-16'];
        $preview = $fetch->preview($this->admin, $input);
        self::assertSame(['ofx', 'previewed', 2, 0], [$preview['kind'], $preview['status'], $preview['rows_count'], $preview['error_count']]);
        self::assertSame('extrato-inter-2026-11-01-a-2026-11-16.ofx', $preview['original_name']);
        self::assertSame(49750, $preview['summary']['balance_check']['bank_cents'], 'Saldo do banco na conferência.');
        $applied = (new \Source\Services\Erp\Imports\Imports($this->pdo, null, $root, true))->apply($this->admin, $preview['id']);
        self::assertSame(['lines' => 2, 'matched' => 1, 'unmatched' => 1, 'already' => 0], $applied['summary']['applied']);
        self::assertSame((int)$this->entries[0], (int)$this->pdo->query("SELECT entry_id FROM erp_bank_statement_lines WHERE fitid = 'inter-TX-1'")->fetchColumn(),
            'O crédito do banco confere com a baixa do boleto.');

        $again = $fetch->preview($this->admin, $input);
        // Saldo que não vem (429 até o fim) não impede o extrato: a prévia sai sem conferir o saldo.
        $this->http->balanceLimited = true;
        BankAdapters::override('inter', new InterBankAdapter((new BankCredentials($this->pdo, $this->vault))->secrets($this->bank), $this->http,
            InterBankAdapter::SANDBOX_URL, null, static function (int $s): void {
            }));
        $noBalance = $fetch->preview($this->admin, $input);
        self::assertNull($noBalance['summary']['ledger_balance_cents']);
        self::assertSame(2, $noBalance['rows_count']);
        $this->http->balanceLimited = false;
        self::assertNotSame($preview['id'], $again['id'], 'Buscar de novo gera outra prévia.');
        self::assertSame(['already', 'already'], array_column($again['preview'], 'status'), 'Linhas que já entraram não repetem.');

        foreach ([['to' => '2026-11-17'], ['from' => '2026-07-01'], ['bank_account_id' => 999999]] as $bad) {
            try {
                $fetch->preview($this->admin, $bad + $input);
                self::fail('Pedido inválido aceito: ' . json_encode($bad));
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $other = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Conta comum', 'bank_code' => '001', 'branch' => '0001', 'account_number' => '555-1', 'kind' => 'corrente'])['id'];
        try {
            $fetch->preview($this->admin, ['bank_account_id' => $other] + $input);
            self::fail('Conta sem banco pela API não busca extrato.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('OFX', $error->getMessage());
        }
        $this->http->statement = [];
        try {
            $fetch->preview($this->admin, ['from' => '2026-10-01', 'to' => '2026-10-31'] + $input);
            self::fail('Período sem lançamento.');
        } catch (\DomainException $error) {
            self::assertStringContainsString('não tem lançamentos', $error->getMessage());
        }
        $this->expectException(\Source\Services\Erp\Registry\RegistryNotFound::class);
        $fetch->preview((new User())->findById($this->createUser(['level' => 2])), $input);
    }

    public function testWholeInvoiceIsIssuedInBatchesWithoutRepeatingRefusals(): void
    {
        $invoiceId = (int)$this->pdo->query('SELECT id FROM erp_invoices ORDER BY id DESC LIMIT 1')->fetchColumn();
        $first = $this->charges->issueForInvoice($this->admin, $invoiceId, $this->bank, 1);
        self::assertCount(1, $first['processed']);
        self::assertSame(['101', 'registered'], [$first['processed'][0]['unit_label'], $first['processed'][0]['status']]);
        self::assertSame(1, $first['remaining']);
        $second = $this->charges->issueForInvoice($this->admin, $invoiceId, $this->bank, 20);
        self::assertSame('rejected', $second['processed'][0]['status'], 'Unidade 102 sem CPF: recusada sem parar o lote.');
        self::assertStringContainsString('CPF', (string)$second['processed'][0]['error']);
        self::assertSame([0, 1], [$second['remaining'], $second['skipped_rejected']]);
        $third = $this->charges->issueForInvoice($this->admin, $invoiceId, $this->bank, 20);
        self::assertSame([[], 0], [$third['processed'], $third['remaining']], 'Nada a repetir: a recusa não volta para o lote.');
        self::assertSame(1, $this->http->count('POST', '/cobranca/v3/cobrancas'), 'Só a unidade válida chegou ao banco.');

        $outsider = $this->createUser(['level' => 2]);
        $this->expectException(\Source\Services\Erp\Registry\RegistryNotFound::class);
        $this->charges->issueForInvoice((new User())->findById($outsider), $invoiceId, $this->bank);
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
