<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\BankBalances;
use Source\Services\Erp\Registry\RegistryNotFound;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Contas a pagar/receber sobre erp_entries e saldo calculado por conta bancária (ADR 0024). */
final class PayablesBalancesTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;
    private int $water;
    private int $fee;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', [
            'condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '341', 'branch' => '1234', 'account_number' => '11111-1',
            'kind' => 'corrente', 'is_main' => '1', 'opening_balance_cents' => '1.000,00', 'opened_on' => '2026-11-01',
        ])['id'];
    }

    public function testPayableIsAnOpenExpenseAndLeavesTheViewWhenPaid(): void
    {
        $payable = $this->service->save($this->admin, 'payables', $this->input(['chart_account_id' => $this->water, 'nature' => 'receita']));
        self::assertSame('despesa', (string)$this->pdo->query('SELECT nature FROM erp_entries WHERE id = ' . (int)$payable['id'])->fetchColumn(), 'Natureza fixa da visão, mesmo com entrada diferente.');
        self::assertSame(1, $this->service->list($this->admin, 'payables')['total']);
        self::assertSame(0, $this->service->list($this->admin, 'receivables')['total']);
        self::assertSame(1, $this->service->list($this->admin, 'entries')['total'], 'É o mesmo lançamento.');

        $this->service->save($this->admin, 'payables', $this->input(['chart_account_id' => $this->water, 'paid_on' => '2026-11-05', 'bank_account_id' => $this->bank]), $payable['id']);
        self::assertSame(0, $this->service->list($this->admin, 'payables')['total'], 'Pago sai de contas a pagar.');
        self::assertSame('2026-11-05', $this->service->find($this->admin, 'entries', $payable['id'])['paid_on']);
        try {
            $this->service->find($this->admin, 'payables', $payable['id']);
            self::fail('Lançamento pago continuou visível em contas a pagar.');
        } catch (RegistryNotFound) {
            self::addToAssertionCount(1);
        }
    }

    public function testViewRejectsAccountOfTheOtherNature(): void
    {
        try {
            $this->service->save($this->admin, 'receivables', $this->input(['chart_account_id' => $this->water]));
            self::fail('Conta a receber aceitou conta de despesa.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('chart_account_id', $exception->errors);
        }
        self::assertSame('receita', (string)$this->pdo->query('SELECT nature FROM erp_entries WHERE id = ' . (int)$this->service->save($this->admin, 'receivables', $this->input(['chart_account_id' => $this->fee]))['id'])->fetchColumn());
    }

    public function testBankBalanceCountsOnlyValidPaidEntriesSinceOpening(): void
    {
        $this->entry('receita', $this->fee, '500,00', '2026-11-10');
        $this->entry('despesa', $this->water, '200,00', '2026-11-12');
        $this->entry('despesa', $this->water, '50,00', '2026-10-30');        // antes do saldo inicial: fora
        $this->entry('despesa', $this->water, '70,00', null);               // em aberto: fora
        $cancelled = $this->entry('despesa', $this->water, '30,00', '2026-11-15');
        $this->pdo->exec("UPDATE erp_entries SET status = 'inactive' WHERE id = {$cancelled}");  // cancelado: fora

        $balances = (new BankBalances($this->pdo))->forUser($this->admin);
        self::assertCount(1, $balances);
        self::assertSame(100000, $balances[0]['opening_cents']);
        self::assertSame(50000, $balances[0]['received_cents']);
        self::assertSame(20000, $balances[0]['paid_cents']);
        self::assertSame(130000, $balances[0]['balance_cents']);

        $outsider = $this->user([$this->beta]);
        self::assertSame([], (new BankBalances($this->pdo))->forUser($outsider), 'Escopo: Beta não vê a conta de Alpha.');
        self::assertSame([], (new BankBalances($this->pdo))->forUser($this->admin, $this->beta));
    }

    private function entry(string $nature, int $account, string $amount, ?string $paidOn): int
    {
        return $this->service->save($this->admin, 'entries', $this->input([
            'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'paid_on' => $paidOn, 'bank_account_id' => $paidOn === null ? null : $this->bank,
        ]))['id'];
    }

    private function input(array $override): array
    {
        return $override + ['condominium_id' => $this->alpha, 'competence' => '2026-11', 'description' => 'Teste', 'amount_cents' => '100,00', 'due_on' => '2026-11-10'];
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
