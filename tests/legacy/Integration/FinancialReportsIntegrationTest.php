<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use InvalidArgumentException;
use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\FinancialReports;
use Source\Services\Erp\Registry\RegistryService;

/** Balancete e prestação de contas com dados reais, conciliação e escopo (ADR 0037). */
final class FinancialReportsIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private User $admin;
    private RegistryService $service;
    private int $fee;
    private int $water;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'name' => 'Movimento', 'bank_code' => '001', 'branch' => '1234',
            'account_number' => '5678-9', 'kind' => 'corrente', 'opening_balance_cents' => '1.000,00', 'opened_on' => '2026-01-01'])['id'];
    }

    public function testTrialBalanceRollsUpAndFollowsScope(): void
    {
        $this->entry($this->alpha, 'receita', $this->fee, '800,00', '2026-11', '2026-11-10');
        $this->entry($this->alpha, 'receita', $this->fee, '200,00', '2026-11');
        $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-12');
        $this->entry($this->alpha, 'despesa', $this->water, '999,00', '2026-12');            // fora do intervalo
        $this->entry($this->beta, 'receita', $this->fee, '50,00', '2026-11');

        $reports = new FinancialReports($this->pdo);
        $alpha = $reports->trialBalance($this->admin, $this->alpha, '2026-11', '2026-11');
        $rows = array_column($alpha['rows'], null, 'code');
        self::assertSame(['1', '1.01', '2', '2.01'], array_map('strval', array_keys($rows)), 'O PHP guarda as chaves "1" e "2" como inteiros.');
        self::assertSame([100000, 80000, 20000], [$rows['1']['planned_cents'], $rows['1']['realized_cents'], $rows['1']['open_cents']]);
        self::assertSame('Receitas', $rows['1']['name']);
        self::assertSame(70000, $alpha['totals']['planned_result_cents']);
        self::assertSame(50000, $alpha['totals']['realized_result_cents']);

        self::assertSame(105000, $reports->trialBalance($this->admin, null, '2026-11', '2026-11')['totals']['planned_revenue_cents'], '"Todos" soma os condomínios do escopo.');
        $limited = $this->user([$this->beta]);
        self::assertSame(5000, $reports->trialBalance($limited, null, '2026-11', '2026-11')['totals']['planned_revenue_cents']);
        try {
            $reports->trialBalance($this->admin, $this->alpha, '2026-12', '2026-11');
            self::fail('Aceitou intervalo invertido.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(CondominiumAccessDenied::class);
        $reports->trialBalance($limited, $this->alpha, '2026-11', '2026-11');
    }

    public function testStatementReconcilesWithBankAccounts(): void
    {
        $this->entry($this->alpha, 'receita', $this->fee, '500,00', '2026-10', '2026-10-15');  // antes do período
        $this->entry($this->alpha, 'receita', $this->fee, '800,00', '2026-11', '2026-11-10');
        $this->entry($this->alpha, 'despesa', $this->water, '300,00', '2026-11', '2026-11-12');
        $this->entry($this->alpha, 'despesa', $this->water, '100,00', '2026-11');              // em aberto: fora do caixa

        $statement = (new FinancialReports($this->pdo))->statement($this->admin, $this->alpha, '2026-11-01', '2026-11-30');
        self::assertSame([80000, 30000, 50000], [$statement['totals']['received_cents'], $statement['totals']['paid_cents'], $statement['totals']['result_cents']]);
        self::assertTrue($statement['totals']['reconciled']);
        self::assertSame(['opening_cents' => 150000, 'received_cents' => 80000, 'paid_cents' => 30000, 'closing_cents' => 200000],
            array_intersect_key($statement['banks'][0], array_flip(['opening_cents', 'received_cents', 'paid_cents', 'closing_cents'])),
            'Saldo anterior = saldo inicial + recebido antes do período.');

        // Lançamento pago sem conta bancária (gravado direto, fora da regra do cadastro) aparece como diferença.
        $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, 'receita', ?, 'Sem banco', 2500, '2026-11', '2026-11-05', '2026-11-05')")->execute([$this->alpha, $this->fee]);
        $off = (new FinancialReports($this->pdo))->statement($this->admin, $this->alpha, '2026-11-01', '2026-11-30');
        self::assertFalse($off['totals']['reconciled']);
        self::assertSame(2500, $off['totals']['difference_cents']);
    }

    /** ADR 0102: na conciliação, a conta só soma o que é do próprio condomínio e até o fim do período. */
    public function testStatementBankUsesOnlyOwnCondominiumUpToThePeriodEnd(): void
    {
        $this->entry($this->alpha, 'receita', $this->fee, '800,00', '2026-11', '2026-11-10');
        // Gravados direto, fora da regra do cadastro: um pago depois do período e um da Beta apontando para a conta da Alpha.
        $insert = $this->pdo->prepare("INSERT INTO erp_entries (condominium_id, nature, chart_account_id, bank_account_id, description, amount_cents, competence, due_on, paid_on)
            VALUES (?, 'receita', ?, ?, ?, ?, ?, ?, ?)");
        $insert->execute([$this->alpha, $this->fee, $this->bank, 'Depois', 40000, '2026-12', '2026-12-05', '2026-12-05']);
        $insert->execute([$this->beta, $this->fee, $this->bank, 'Inconsistente', 7000, '2026-11', '2026-11-05', '2026-11-05']);

        $bank = (new FinancialReports($this->pdo))->statement($this->admin, null, '2026-11-01', '2026-11-30')['banks'][0];
        self::assertSame([100000, 80000, 0, 180000], [$bank['opening_cents'], $bank['received_cents'], $bank['paid_cents'], $bank['closing_cents']]);
    }

    private function entry(int $condominium, string $nature, int $account, string $amount, string $competence, ?string $paidOn = null): int
    {
        $input = ['condominium_id' => $condominium, 'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'competence' => $competence, 'description' => 'Teste', 'due_on' => $competence . '-10'];
        if ($paidOn !== null) {
            $input += ['paid_on' => $paidOn, 'bank_account_id' => $this->bank];
        }
        return $this->service->save($this->admin, 'entries', $input)['id'];
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
