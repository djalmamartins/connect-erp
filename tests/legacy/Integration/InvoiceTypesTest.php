<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Tipos de faturas (AP-168) e campo 'ref' para o plano de contas (ADR 0020). */
final class InvoiceTypesTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->account(null, '1', 'receita', 'sintetica', 'Receitas');
        $this->account(null, '2', 'despesa', 'sintetica', 'Despesas');
    }

    public function testTypeMustPointToActiveAnalyticRevenueAccount(): void
    {
        $fee = $this->account(null, '1.01', 'receita', 'analitica', 'Taxa condominial');
        $group = $this->account(null, '1.02', 'receita', 'sintetica', 'Outras receitas');
        $expense = $this->account(null, '2.01', 'despesa', 'analitica', 'Água');

        $type = $this->type(null, 'Taxa condominial', $fee['id']);
        self::assertSame($fee['id'], $type['chart_account_id']);

        $this->assertInvalid('chart_account_id', fn () => $this->type(null, 'Grupo', $group['id']), 'Conta sintética.');
        $this->assertInvalid('chart_account_id', fn () => $this->type(null, 'Despesa', $expense['id']), 'Conta de despesa.');
        $this->assertInvalid('chart_account_id', fn () => $this->type(null, 'Inexistente', 999999), 'Conta inexistente.');

        $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa condominial', 'status' => 'inactive'], $fee['id']);
        $this->assertInvalid('chart_account_id', fn () => $this->type(null, 'Fundo de reserva', $fee['id']), 'Conta inativa.');
    }

    public function testCondominiumAccountOnlyServesItsOwnCondominium(): void
    {
        $own = $this->account($this->alpha, '1.90', 'receita', 'analitica', 'Aluguel do salão');

        $type = $this->type($this->alpha, 'Aluguel do salão', $own['id']);
        self::assertSame($this->alpha, $type['condominium_id']);
        $this->assertInvalid('chart_account_id', fn () => $this->type($this->beta, 'Aluguel', $own['id']), 'Conta de outro condomínio.');
        $this->assertInvalid('chart_account_id', fn () => $this->type(null, 'Aluguel padrão', $own['id']), 'Tipo padrão não usa conta de condomínio.');
    }

    public function testOptionsFollowScopeAndFilters(): void
    {
        $this->account(null, '1.01', 'receita', 'analitica', 'Taxa condominial');
        $this->account(null, '2.01', 'despesa', 'analitica', 'Água');
        $this->account($this->alpha, '1.90', 'receita', 'analitica', 'Salão Alpha');
        $this->account($this->beta, '1.91', 'receita', 'analitica', 'Salão Beta');

        $local = $this->user([$this->alpha]);
        $options = $this->service->references($local, \Source\Services\Erp\Registry\Definitions::get('invoice_types'))['chart_account_id'];
        self::assertSame(['1.01 — Taxa condominial', '1.90 — Salão Alpha'], array_column($options, 'label'));
    }

    public function testAccountUsedByActiveTypeCannotBeDeletedAndNamesAreUniquePerPlan(): void
    {
        $fee = $this->account(null, '1.01', 'receita', 'analitica', 'Taxa condominial');
        $type = $this->type(null, 'Taxa condominial', $fee['id']);
        $this->assertInvalid('name', fn () => $this->type(null, 'Taxa condominial', $fee['id']), 'Nome repetido no padrão.');
        self::assertSame('Taxa condominial', $this->type($this->alpha, 'Taxa condominial', $fee['id'])['name'], 'Condomínio pode ter tipo próprio com o mesmo nome.');

        try {
            $this->service->delete($this->admin, 'chart', $fee['id']);
            self::fail('Excluiu conta usada por tipo de fatura.');
        } catch (RegistryInUse) {
            self::addToAssertionCount(1);
        }
        foreach ($this->service->list($this->admin, 'invoice_types')['data'] as $row) {
            $this->service->delete($this->admin, 'invoice_types', $row['id']);
        }
        $this->service->delete($this->admin, 'chart', $fee['id']);
        self::assertSame(0, $this->service->list($this->admin, 'invoice_types')['total']);
        self::assertNotSame(0, $type['id']);
    }

    private function account(?int $condominium, string $code, string $nature, string $kind, string $name): array
    {
        return $this->service->save($this->admin, 'chart', ['condominium_id' => $condominium, 'code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name]);
    }

    private function type(?int $condominium, string $name, int $account): array
    {
        return $this->service->save($this->admin, 'invoice_types', ['condominium_id' => $condominium, 'name' => $name, 'chart_account_id' => $account, 'recurrence' => 'mensal']);
    }

    private function assertInvalid(string $field, callable $call, string $message = ''): void
    {
        try {
            $call();
            self::fail('Aceitou dado inválido. ' . $message);
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, $message . ' ' . json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
        }
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
