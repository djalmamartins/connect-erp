<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\InstallmentPayables;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0195: a despesa parcelada grava N contas a pagar pelo formulário, numa transação (tudo ou nada). */
final class InstallmentPayablesTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;
    private int $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        // Acesso a todos os condomínios, como no EntriesTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        $this->registry->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->registry->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->revenue = $this->registry->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->account = $this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Obras'])['id'];
    }

    public function testCreatesAllInstallmentsAsPayables(): void
    {
        $result = (new InstallmentPayables($this->pdo))->create($this->admin, $this->input());
        self::assertCount(3, $result['entries']);
        $rows = $this->pdo->query('SELECT nature, description, amount_cents, due_on, competence, paid_on, document_number, created_by FROM erp_entries ORDER BY due_on')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([['despesa', 'Reforma do hall (1/3)', 33334, '2026-11-30', '2026-11'], ['despesa', 'Reforma do hall (2/3)', 33333, '2026-12-30', '2026-12'],
            ['despesa', 'Reforma do hall (3/3)', 33333, '2027-01-30', '2027-01']],
            array_map(static fn (array $r): array => [$r['nature'], $r['description'], (int)$r['amount_cents'], $r['due_on'], $r['competence']], $rows));
        self::assertSame([null, 'NF 123', (int)$this->admin->id], [$rows[0]['paid_on'], $rows[0]['document_number'], (int)$rows[0]['created_by']], 'Nascem em aberto.');
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries' AND action = 'create'")->fetchColumn(),
            'Cada parcela auditada como lançamento.');
    }

    public function testClosedCompetenceOrWrongAccountRollsBackEverything(): void
    {
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-12', 'closed')")->execute([$this->condominium]);
        $this->assertRefused($this->input(), 'competence', 'Parcela 2 de 3.');
        $this->assertRefused(['chart_account_id' => $this->revenue, 'first_due_on' => '2027-02-10'] + $this->input(), 'chart_account_id', 'Parcela 1 de 3.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries')->fetchColumn(), 'Tudo ou nada: nenhuma parcela fica.');
    }

    private function assertRefused(array $input, string $field, string $installment): void
    {
        try {
            (new InstallmentPayables($this->pdo))->create($this->admin, $input);
            self::fail('Parcelou com parcela inválida.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
            self::assertSame($installment, $exception->errors['_installment'] ?? null);
        }
    }

    private function input(): array
    {
        return ['condominium_id' => $this->condominium, 'chart_account_id' => $this->account, 'description' => 'Reforma do hall', 'total' => '1.000,00',
            'installments' => 3, 'first_due_on' => '2026-11-30', 'document_number' => 'NF 123'];
    }
}
