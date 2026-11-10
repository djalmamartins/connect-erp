<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0152: num banco novo a despesa precisa salvar com o plano padrão. Antes, o plano padrão só tinha as
 * contas do Balanço (7/8/9) e o lançamento oferecia "8.1 Contas a pagar", que é passivo: dava erro.
 */
final class ChartDefaultEntriesTest extends TestCase
{
    private const MIGRATION = __DIR__ . '/../../../database/migrations/';

    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?), (?, ?)')->execute(['Alpha', 'active', 'Beta', 'active']);
        $this->alpha = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'Alpha'")->fetchColumn();
        $this->beta = (int)$this->pdo->query("SELECT id FROM condominiums WHERE name = 'Beta'")->fetchColumn();
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        // Banco novo: as contas do Balanço (0704) e o plano padrão de receitas e despesas (0709).
        $this->pdo->exec((string)file_get_contents(self::MIGRATION . '20261109_0704_chart_patrimonial_natures.sql'));
        $this->pdo->exec((string)file_get_contents(self::MIGRATION . '20261110_0709_chart_default_income_expense.sql'));
    }

    public function testExpenseWithTheDefaultPlanSavesInPayables(): void
    {
        $electric = $this->account('2.04.01');
        $saved = $this->service->save($this->admin, 'payables', [
            'condominium_id' => $this->alpha, 'competence' => 'nov/2026', 'description' => 'Manutenção elétrica',
            'amount_cents' => '350,00', 'chart_account_id' => $electric, 'due_on' => '20/11/2026',
        ]);
        self::assertSame('despesa', $this->pdo->query('SELECT nature FROM erp_entries WHERE id = ' . (int)$saved['id'])->fetchColumn());
        self::assertSame('2026-11', $saved['competence']);
        self::assertNull($saved['cost_center_id'], 'Centro de custo é opcional.');
        self::assertNull($saved['bank_account_id'], 'Conta bancária só é pedida ao dar baixa.');
    }

    public function testPatrimonialAccountIsNotOfferedNorAccepted(): void
    {
        $payable = $this->account('8.1');
        try {
            $this->service->save($this->admin, 'entries', [
                'condominium_id' => $this->alpha, 'nature' => 'despesa', 'competence' => '2026-11', 'description' => 'Manutenção elétrica',
                'amount_cents' => '350,00', 'chart_account_id' => $payable, 'due_on' => '2026-11-20',
            ]);
            self::fail('Aceitou a conta 8.1 Contas a pagar (passivo) num lançamento de despesa.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('chart_account_id', $exception->errors);
        }

        $options = $this->service->references($this->admin, RegistryService::definition('entries'))['chart_account_id'];
        $codes = array_map(static fn (array $o): string => explode(' — ', $o['label'])[0], $options);
        self::assertContains('2.04.01', $codes);
        self::assertContains('1.01', $codes);
        self::assertNotContains('8.1', $codes, 'Conta do Balanço não aparece no lançamento.');
        self::assertNotContains('2.04', $codes, 'Sintética não recebe lançamento.');
        $byCode = array_combine($codes, $options);
        self::assertSame(['nature' => 'despesa'], $byCode['2.04.01']['match'], 'A opção diz a natureza para o formulário filtrar.');
        self::assertSame(['nature' => 'receita'], $byCode['1.01']['match']);

        $payables = $this->service->references($this->admin, RegistryService::definition('payables'))['chart_account_id'];
        self::assertSame([], array_values(array_filter($payables, static fn (array $o): bool => ($o['match']['nature'] ?? '') !== 'despesa')), 'Contas a pagar só lista despesas.');
    }

    public function testMigrationIsIdempotentAndKeepsTheUserPlan(): void
    {
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts')->fetchColumn();
        $this->pdo->exec((string)file_get_contents(self::MIGRATION . '20261110_0709_chart_default_income_expense.sql'));
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_chart_accounts')->fetchColumn());

        $this->pdo->exec("DELETE FROM erp_chart_accounts WHERE code LIKE '1%' OR code LIKE '2%'");
        $this->pdo->exec("INSERT INTO erp_chart_accounts (condominium_id, code, name, nature, kind) VALUES (NULL, '2', 'Minhas despesas', 'despesa', 'analitica')");
        $this->pdo->exec((string)file_get_contents(self::MIGRATION . '20261110_0709_chart_default_income_expense.sql'));
        self::assertSame('Minhas despesas', $this->pdo->query("SELECT name FROM erp_chart_accounts WHERE code = '2'")->fetchColumn());
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM erp_chart_accounts WHERE code LIKE '2.%'")->fetchColumn(), 'Sem filha pendurada em conta analítica do usuário.');
    }

    private function account(string $code): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM erp_chart_accounts WHERE condominium_id IS NULL AND code = ? AND deleted_at IS NULL');
        $stmt->execute([$code]);
        return (int)$stmt->fetchColumn();
    }

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
