<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MonthClosing;
use Source\Services\Erp\Registry\RegistryService;

/** Financeiro no contexto do condomínio da barra superior (ADR 0032). */
final class FinanceContextIntegrationTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private int $gamma;
    private RegistryService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->gamma = $this->condominium('Gamma');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta, $this->gamma], true);
    }

    public function testStandardRowsAppearInCondominiumContextOnlyWhenAsked(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas (padrão)']);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->alpha, 'code' => '9', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Própria Alpha']);
        $this->service->save($this->admin, 'chart', ['condominium_id' => $this->beta, 'code' => '8', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Própria Beta']);

        $names = fn (array $filters): array => array_column($this->service->list($this->admin, 'chart', $filters)['data'], 'name');

        $screen = $names(['condominium_id' => $this->alpha, 'include_standard' => true]);
        self::assertContains('Receitas (padrão)', $screen, 'Tela no contexto do Alpha mostra o padrão da Connect.');
        self::assertContains('Própria Alpha', $screen);
        self::assertNotContains('Própria Beta', $screen, 'Contas de outro condomínio ficam de fora.');

        self::assertSame(['Própria Alpha'], $names(['condominium_id' => $this->alpha]), 'Sem include_standard (API), só as do condomínio.');
        self::assertCount(3, $names([]), '"Todos" = escopo inteiro.');

        // Cadastro de escopo required ignora include_standard (não existe padrão sem condomínio).
        $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->beta, 'name' => 'Movimento Beta', 'bank_code' => '001', 'branch' => '1234', 'account_number' => '5678-9', 'kind' => 'corrente']);
        self::assertSame(0, $this->service->list($this->admin, 'bank_accounts', ['condominium_id' => $this->alpha, 'include_standard' => true])['total']);
    }

    public function testClosingOverviewFollowsContextAndScope(): void
    {
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->service->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $fee = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $water = $this->service->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Água'])['id'];
        $entry = fn (int $condominium, string $nature, int $account, string $amount) => $this->service->save($this->admin, 'entries', [
            'condominium_id' => $condominium, 'nature' => $nature, 'chart_account_id' => $account, 'amount_cents' => $amount,
            'competence' => '2026-11', 'description' => 'Teste', 'due_on' => '2026-11-10',
        ]);
        $entry($this->alpha, 'receita', $fee, '1.000,00');
        $entry($this->alpha, 'despesa', $water, '300,00');
        $entry($this->beta, 'receita', $fee, '500,00');
        $closings = new MonthClosing($this->pdo);
        $closings->close($this->admin, $this->alpha, '2026-11');

        $one = $closings->overview($this->admin, $this->alpha, '2026-11');
        self::assertCount(1, $one['rows']);
        self::assertSame(['closed', 100000, 30000, 100000, 30000],[$one['rows'][0]['status'], $one['rows'][0]['revenue_cents'], $one['rows'][0]['expense_cents'], $one['rows'][0]['open_revenue_cents'], $one['rows'][0]['open_expense_cents']]);

        $all = $closings->overview($this->admin, null, '2026-11');
        self::assertSame(['Alpha', 'Beta', 'Gamma'], array_column($all['rows'], 'condominium_name'), '"Todos" lista cada condomínio do escopo, mesmo sem lançamentos.');
        self::assertSame(['condominiums' => 3, 'closed' => 1, 'open' => 2, 'revenue_cents' => 150000, 'expense_cents' => 30000,
            'open_revenue_cents' => 150000, 'open_expense_cents' => 30000, 'result_cents' => 120000], $all['totals']);

        $limited = $this->user([$this->beta]);
        self::assertSame([$this->beta], array_column($closings->overview($limited, null, '2026-11')['rows'], 'condominium_id'), '"Todos" = só o escopo do usuário.');
        $this->expectException(CondominiumAccessDenied::class);
        $closings->overview($limited, $this->alpha, '2026-11');
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
