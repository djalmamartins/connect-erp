<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Agreements;
use Source\Services\Erp\CollectionReports;
use Source\Services\Erp\Registry\RegistryService;

/**
 * Relatórios de cobrança (ADR 0081 e 0067) com dois condomínios: o contexto "Todos" soma só o escopo
 * do usuário, cada relatório e cada visão de um usuário do Beta trazem só o Beta, e o CSV sai com o
 * documento mascarado e com fórmulas neutralizadas, inclusive em texto vindo do cadastro.
 */
final class CollectionReportsScopeIntegrationTest extends TestCase
{
    private RegistryService $service;
    private User $admin;
    /** @var array<string, array{condominium:int, account:int, bank:int, unit:int, cpf:string}> */
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $ids = [];
        foreach (['Alpha', 'Beta'] as $name) {
            $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
            $ids[$name] = (int)$this->pdo->lastInsertId();
        }
        $this->admin = $this->user(array_values($ids), true);
        $this->service = new RegistryService($this->pdo);
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $account = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        foreach ($ids as $name => $condominium) {
            $bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $condominium, 'name' => "Movimento {$name}", 'bank_code' => '001',
                'branch' => '1234', 'account_number' => $name === 'Alpha' ? '5678-9' : '1111-1', 'kind' => 'corrente'])['id'];
            $unit = $this->service->save($this->admin, 'units', ['condominium_id' => $condominium, 'number' => $name === 'Alpha' ? '101' : '201', 'type' => 'apartamento'])['id'];
            $cpf = self::validCpf();
            $this->service->save($this->admin, 'residents', ['condominium_id' => $condominium, 'unit_id' => $unit, 'relationship' => 'owner',
                'name' => $name === 'Alpha' ? '@SUM(A1) Morador' : 'Morador Beta', 'document' => $cpf, 'email' => strtolower($name) . '@morador.test']);
            $this->sites[$name] = ['condominium' => $condominium, 'account' => $account, 'bank' => $bank, 'unit' => $unit, 'cpf' => $cpf];
        }
    }

    public function testEachReportStaysInScopeAndCsvIsSafe(): void
    {
        $today = date('Y-m-d');
        $amounts = ['Alpha' => '500,00', 'Beta' => '200,00'];
        $agreed = [];
        foreach ($this->sites as $name => $site) {
            $overdue = $this->entry($site, '=HYPERLINK("http://x") ' . $name, $amounts[$name], '-50 days');
            $this->entry($site, 'Outra taxa ' . $name, $amounts[$name], '-20 days');
            $paid = $this->entry($site, 'Paga ' . $name, $amounts[$name], '-5 days');
            $this->pdo->prepare('UPDATE erp_entries SET paid_on = CURDATE(), bank_account_id = ? WHERE id = ?')->execute([$site['bank'], $paid]);
            $agreed[$name] = $overdue;
        }
        (new Agreements($this->pdo))->create($this->admin, ['unit_id' => $this->sites['Alpha']['unit'], 'entry_ids' => [$agreed['Alpha']], 'installments' => 2,
            'first_due_on' => date('Y-m-d', strtotime('+10 days')), 'chart_account_id' => $this->sites['Alpha']['account'], 'fees_cents' => '30,00']);
        $reports = new CollectionReports($this->pdo);

        // 1. "Todos" do administrador soma os dois condomínios.
        self::assertSame(2, count($reports->delinquents($this->admin, null, null, 'general')['rows']));
        self::assertSame(50000 + 20000, $reports->receipts($this->admin, null, $today, $today)['totals']['amount_cents']);

        // 2. Um usuário só do Beta: todo relatório e toda visão trazem só o Beta.
        $beta = $this->user([$this->sites['Beta']['condominium']]);
        $betaId = $this->sites['Beta']['condominium'];
        $only = static fn (array $rows): array => array_values(array_unique(array_map('intval', array_column($rows, 'condominium_id'))));
        foreach (CollectionReports::DELINQUENT_VIEWS as $view) {
            self::assertSame([$betaId], $only($reports->delinquents($beta, null, null, $view)['rows']), "Visão {$view}");
        }
        self::assertSame([], $reports->delinquents($beta, $this->sites['Alpha']['condominium'], null, 'unit')['rows'], 'Condomínio de fora: nada.');
        self::assertSame(0, $reports->agreements($beta, null, $today, $today)['totals']['agreements'], 'O acordo é do Alpha.');
        self::assertSame([], $reports->agreementFees($beta, null, $today, $today)['condominiums']);
        foreach (CollectionReports::RECEIPT_GROUPS as $group) {
            self::assertSame([$betaId], $only($reports->receipts($beta, null, $today, $today, $group)['rows']), "Recebimentos por {$group}");
        }
        foreach ([
            fn () => $reports->agreements($beta, $this->sites['Alpha']['condominium'], $today, $today),
            fn () => $reports->agreementFees($beta, $this->sites['Alpha']['condominium'], $today, $today),
            fn () => $reports->receipts($beta, $this->sites['Alpha']['condominium'], $today, $today),
        ] as $i => $call) {
            try {
                $call();
                self::fail("Chamada {$i} leu o Alpha.");
            } catch (CondominiumAccessDenied) {
                self::addToAssertionCount(1);
            }
        }

        // 3. CSV: documento mascarado, fórmulas neutralizadas e nada do Alpha no arquivo do Beta.
        $resident = CollectionReports::csv(CollectionReports::csvTable('delinquents-resident', $reports->delinquents($this->admin, null, null, 'resident')));
        foreach ($this->sites as $site) {
            $digits = (string)preg_replace('/\D/', '', $site['cpf']);
            self::assertStringNotContainsString($digits, $resident, 'CPF completo não sai no CSV.');
            self::assertStringNotContainsString(substr($digits, 0, 9), $resident);
            self::assertStringContainsString('*********' . substr($digits, -2), $resident);
        }
        self::assertStringContainsString(";'@SUM(A1) Morador;", $resident);
        $detailed = CollectionReports::csv(CollectionReports::csvTable('delinquents-detailed', $reports->delinquents($beta, null, null, 'detailed')));
        self::assertStringContainsString(";\"'=HYPERLINK(\"\"http://x\"\") Beta\";", $detailed, 'Fórmula do cadastro vira texto.');
        self::assertStringNotContainsString('Alpha', $detailed);
        self::assertStringNotContainsString(';=', $detailed);
    }

    /** @param array{condominium:int, account:int, unit:int} $site */
    private function entry(array $site, string $description, string $amount, string $due): int
    {
        $date = date('Y-m-d', strtotime($due));
        return $this->service->save($this->admin, 'entries', ['condominium_id' => $site['condominium'], 'nature' => 'receita', 'chart_account_id' => $site['account'],
            'unit_id' => $site['unit'], 'description' => $description, 'amount_cents' => $amount, 'competence' => substr($date, 0, 7), 'due_on' => $date])['id'];
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
