<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BankBalances;
use Source\Services\Erp\Banking\BankCharges;
use Source\Services\Erp\Banking\BankReturns;
use Source\Services\Erp\Banking\SandboxBankAdapter;
use Source\Services\Erp\EntryShortcuts;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Invoices;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\UnitDocuments;

/**
 * Lançamentos avulsos e recibo de ponta a ponta (ciclo 24, ADR 0057), encadeados com o ciclo
 * financeiro: desconto antes do boleto → boleto pelo valor com desconto → retorno dá baixa →
 * recibo da conta paga, conferido pelo código → multa avulsa e transferência entre contas →
 * saldos e prestação de contas batendo. No fim, um usuário de outro condomínio não alcança nada.
 */
final class ShortcutsCycleIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
    }

    public function testDiscountChargeReceiptTransferAndScope(): void
    {
        $today = date('Y-m-d');
        $due = date('Y-m-d', strtotime('+10 days'));
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $type = $this->service->save($this->admin, 'invoice_types', ['name' => 'Taxa condominial', 'chart_account_id' => $fee, 'recurrence' => 'mensal', 'default_amount_cents' => '500,00'])['id'];
        $units = [];
        foreach (['101', '102'] as $number) {
            $units[$number] = $this->service->save($this->admin, 'units', ['condominium_id' => $this->alpha, 'number' => $number, 'type' => 'apartamento'])['id'];
        }
        $bankA = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente'])['id'];
        $bankB = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Reserva', 'bank_code' => '001',
            'branch' => '1234', 'account_number' => '9999-0', 'kind' => 'poupanca'])['id'];
        $invoices = new Invoices($this->pdo);
        $invoice = $invoices->issue($this->admin, $invoices->prepare($this->admin, ['condominium_id' => $this->alpha, 'invoice_type_id' => $type,
            'competence' => substr($due, 0, 7), 'due_on' => $due])['id']);
        $entries = array_map('intval', array_column($invoice['items'], 'entry_id', 'unit_label'));
        $shortcuts = new EntryShortcuts($this->pdo);

        // 1. Desconto antes do boleto: o título nasce com o valor já reduzido.
        $discount = $shortcuts->discount($this->admin, $entries['101'], '50,00', 'Acordo de assembleia');
        self::assertSame([50000, 5000, 45000], [$discount['before_cents'], $discount['discount_cents'], $discount['after_cents']]);
        $charges = new BankCharges($this->pdo);
        $charge = $charges->issue($this->admin, $entries['101'], $bankA);
        self::assertSame(45000, $charge['amount_cents']);

        // 2. Retorno pagando o valor com desconto dá baixa; o recibo cobre a conta paga, uma vez só.
        $result = (new BankReturns($this->pdo))->process($this->admin, $bankA, 'retorno.txt', SandboxBankAdapter::buildReturn([
            ['event_key' => 'AVULSO-1', 'kind' => 'paid', 'our_number' => $charge['our_number'], 'amount_cents' => 45000, 'occurred_on' => $today],
        ]));
        self::assertSame(['applied'], array_column($result['events'], 'outcome'));
        $documents = new UnitDocuments($this->pdo);
        $receipt = $documents->issue($this->admin, ['kind' => 'receipt', 'unit_id' => $units['101'], 'entry_ids' => [$entries['101']]]);
        self::assertSame(['receipt', 45000], [$receipt['kind'], $receipt['total_cents']]);
        self::assertSame($receipt['id'], $documents->verify($this->admin, (string)$receipt['verification_code'])['id'], 'O código confere o recibo.');

        // 3. Multa avulsa da 102 fica travada pela origem; transferência move saldo entre as contas.
        $fine = $shortcuts->createCharge($this->admin, ['condominium_id' => $this->alpha, 'kind' => 'fine', 'description' => 'Barulho após 22h',
            'chart_account_id' => $fee, 'competence' => substr($due, 0, 7), 'due_on' => $due, 'amount_cents' => '100,00',
            'unit_ids' => [$units['102']], 'related_entry_id' => $entries['102'], 'reason' => 'Regulamento interno, art. 12']);
        self::assertSame([10000, 1], [$fine['total_cents'], count($fine['entries'])]);
        $transfer = $shortcuts->transfer($this->admin, ['condominium_id' => $this->alpha, 'from_bank_account_id' => $bankA, 'to_bank_account_id' => $bankB,
            'amount_cents' => '200,00', 'transferred_on' => $today, 'description' => 'Fundo de reserva']);
        $balances = array_column((new BankBalances($this->pdo))->forUser($this->admin, $this->alpha), 'balance_cents', 'id');
        self::assertSame([25000, 20000], [$balances[$bankA], $balances[$bankB]], 'Recebido 450,00 na Movimento, 200,00 passados para a Reserva.');

        // 4. Prestação de contas do dia: entra só o pagamento; a transferência soma zero e a conciliação bate.
        $reports = new FinancialReports($this->pdo);
        $statement = $reports->statement($this->admin, $this->alpha, $today, $today);
        $received = array_sum(array_column(array_filter($statement['accounts'], static fn (array $a): bool => $a['nature'] === 'receita'), 'total_cents'));
        self::assertSame(45000, $received);
        self::assertTrue($statement['totals']['reconciled']);

        // 5. Usuário só do Beta não alcança nada do Alpha, nem por id nem pelo código do recibo.
        $outsider = $this->user([$this->beta]);
        foreach ([
            fn () => $shortcuts->discount($outsider, $entries['102'], '1,00', 'Fora do escopo'),
            fn () => $shortcuts->adjustments($outsider, $entries['101']),
            fn () => $shortcuts->findCharge($outsider, (int)$fine['id']),
            fn () => $shortcuts->cancelCharge($outsider, (int)$fine['id'], 'Fora do escopo'),
            fn () => $shortcuts->createCharge($outsider, ['condominium_id' => $this->alpha, 'kind' => 'extra']),
            fn () => $shortcuts->findTransfer($outsider, (int)$transfer['id']),
            fn () => $shortcuts->cancelTransfer($outsider, (int)$transfer['id'], 'Fora do escopo'),
            fn () => $shortcuts->transfer($outsider, ['condominium_id' => $this->alpha]),
            fn () => $documents->find($outsider, (int)$receipt['id']),
            fn () => $documents->verify($outsider, (string)$receipt['verification_code']),
            fn () => $documents->issue($outsider, ['kind' => 'receipt', 'unit_id' => $units['101'], 'entry_ids' => [$entries['101']]]),
            fn () => $reports->statement($outsider, $this->alpha, $today, $today),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} alcançou dado de outro condomínio.");
            } catch (RegistryNotFound|CondominiumAccessDenied) {
                self::addToAssertionCount(1);
            }
        }
        self::assertSame([0, 0], [$shortcuts->listCharges($outsider)['total'], $shortcuts->listTransfers($outsider)['total']]);
        self::assertSame('active', $shortcuts->findTransfer($this->admin, (int)$transfer['id'])['status'], 'A tentativa de fora não cancelou.');
    }

    private function condominium(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }

    /** @param list<int> $condominiums */
    private function user(array $condominiums, bool $global = false): User
    {
        $id = $this->createUser(['level' => 2]);
        $scope = new CondominiumScope($this->pdo);
        foreach ($condominiums as $condominium) {
            $scope->grant($id, $condominium);
        }
        if ($global) {
            $slug = 'role_' . $id;
            $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
            $role = (int)$this->pdo->lastInsertId();
            $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
            $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
            $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
            $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        }
        return (new User())->findById($id);
    }
}
