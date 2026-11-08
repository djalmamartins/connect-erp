<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryInUse;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** Lançamentos (AP-020): conta, natureza, conta bancária do condomínio e integridade (ADR 0022). */
final class EntriesTest extends TestCase
{
    private int $alpha;
    private int $beta;
    private RegistryService $service;
    private User $admin;
    private int $water;
    private int $fee;
    private int $bankAlpha;
    private int $bankBeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->condominium('Alpha');
        $this->beta = $this->condominium('Beta');
        $this->service = new RegistryService($this->pdo);
        $this->admin = $this->user([$this->alpha, $this->beta], true);
        $this->chart('1', 'receita', 'sintetica', 'Receitas');
        $this->chart('2', 'despesa', 'sintetica', 'Despesas');
        $this->fee = $this->chart('1.01', 'receita', 'analitica', 'Taxa condominial');
        $this->water = $this->chart('2.01', 'despesa', 'analitica', 'Água');
        $this->bankAlpha = $this->bank($this->alpha, '11111-1');
        $this->bankBeta = $this->bank($this->beta, '22222-2');
    }

    public function testExpenseIsSavedAndPaidWithOwnBankAccount(): void
    {
        $entry = $this->entry(['nature' => 'despesa', 'chart_account_id' => $this->water]);
        self::assertSame(15000, $entry['amount_cents']);
        self::assertNull($entry['paid_on']);

        $paid = $this->service->save($this->admin, 'entries', $this->input(['nature' => 'despesa', 'chart_account_id' => $this->water, 'paid_on' => '2026-11-09', 'bank_account_id' => $this->bankAlpha]), $entry['id']);
        self::assertSame('2026-11-09', $paid['paid_on']);
        self::assertSame($this->bankAlpha, $paid['bank_account_id']);
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries'")->fetchColumn(), 'Criação e baixa auditadas.');
    }

    public function testAccountMustBeAnalyticAndOfTheSameNature(): void
    {
        $this->assertInvalid('chart_account_id', fn () => $this->entry(['nature' => 'receita', 'chart_account_id' => $this->water]), 'Despesa em lançamento de receita.');
        $group = (int)$this->pdo->query("SELECT id FROM erp_chart_accounts WHERE code = '2'")->fetchColumn();
        $this->assertInvalid('chart_account_id', fn () => $this->entry(['nature' => 'despesa', 'chart_account_id' => $group]), 'Conta sintética.');
        self::assertSame('receita', $this->entry(['nature' => 'receita', 'chart_account_id' => $this->fee])['nature']);
    }

    public function testBankAccountAndScopeBelongToTheEntryCondominium(): void
    {
        $this->assertInvalid('bank_account_id', fn () => $this->entry(['nature' => 'despesa', 'chart_account_id' => $this->water, 'paid_on' => '2026-11-09', 'bank_account_id' => $this->bankBeta]), 'Conta bancária de outro condomínio.');

        $local = $this->user([$this->alpha]);
        $this->assertInvalid('condominium_id', fn () => $this->service->save($local, 'entries', $this->input(['condominium_id' => $this->beta, 'nature' => 'despesa', 'chart_account_id' => $this->water])), 'Condomínio fora do escopo.');
        $this->entry(['nature' => 'despesa', 'chart_account_id' => $this->water]);
        $this->entry(['condominium_id' => $this->beta, 'nature' => 'despesa', 'chart_account_id' => $this->water]);
        self::assertSame(1, $this->service->list($local, 'entries')['total'], 'Alpha só vê os próprios lançamentos.');
    }

    public function testReferencedRegistriesCannotBeDeletedWhileEntryIsActive(): void
    {
        $entry = $this->entry(['nature' => 'despesa', 'chart_account_id' => $this->water, 'paid_on' => '2026-11-09', 'bank_account_id' => $this->bankAlpha]);
        foreach ([['chart', $this->water], ['bank_accounts', $this->bankAlpha]] as [$slug, $id]) {
            try {
                $this->service->delete($this->admin, $slug, $id);
                self::fail("Excluiu {$slug} usado por lançamento ativo.");
            } catch (RegistryInUse) {
                self::addToAssertionCount(1);
            }
        }
        $this->service->delete($this->admin, 'entries', $entry['id']);
        $this->service->delete($this->admin, 'bank_accounts', $this->bankAlpha);
        self::assertSame(0, $this->service->list($this->admin, 'entries')['total']);
    }

    private function input(array $override): array
    {
        return $override + [
            'condominium_id' => $this->alpha, 'competence' => '2026-11', 'description' => 'Lançamento de teste',
            'amount_cents' => '150,00', 'due_on' => '2026-11-10',
        ];
    }

    private function entry(array $override): array
    {
        return $this->service->save($this->admin, 'entries', $this->input($override));
    }

    private function chart(string $code, string $nature, string $kind, string $name): int
    {
        return $this->service->save($this->admin, 'chart', ['code' => $code, 'nature' => $nature, 'kind' => $kind, 'name' => $name])['id'];
    }

    private function bank(int $condominium, string $number): int
    {
        return $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $condominium, 'name' => 'Conta ' . $number, 'bank_code' => '341', 'branch' => '1234', 'account_number' => $number, 'kind' => 'corrente'])['id'];
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
