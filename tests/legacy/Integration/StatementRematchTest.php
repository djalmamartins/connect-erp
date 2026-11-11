<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\StatementRematch;

/** ADR 0209: o extrato importado antes da baixa é conciliado na nova conferência, pela regra da importação. */
final class StatementRematchTest extends TestCase
{
    private int $condominium;
    private int $other;
    private User $admin;
    private RegistryService $registry;
    private int $account;
    private int $bank;
    private int $import;

    protected function setUp(): void
    {
        parent::setUp();
        $this->condominium = $this->condominiumNamed('Alpha');
        $this->other = $this->condominiumNamed('Beta');
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
        $this->account = $this->registry->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Conta 1', 'bank_code' => '341',
            'branch' => '1234', 'account_number' => '11111-1', 'kind' => 'corrente'])['id'];
        $this->pdo->prepare("INSERT INTO erp_imports (condominium_id, kind, original_name, sha256, storage_path, size_bytes, status) VALUES (?, 'ofx', 'extrato.ofx', ?, 'x.ofx', 10, 'applied')")
            ->execute([$this->condominium, str_repeat('c', 64)]);
        $this->import = (int)$this->pdo->lastInsertId();
    }

    public function testPaidAfterImportIsMatchedAmbiguousStaysPending(): void
    {
        // O extrato foi importado antes das baixas: as três linhas ficaram pendentes.
        $late = $this->line('F1', '2026-11-05', 15000, 'PIX RECEBIDO JOAO DA SILVA');
        $twin = $this->line('F2', '2026-11-06', 8000);
        $far = $this->line('F3', '2026-11-01', 5000);
        // Depois, as baixas no ERP.
        $paid = $this->entry('Taxa 101', 15000, '2026-11-06');
        $this->entry('Taxa 102', 8000, '2026-11-06');
        $this->entry('Taxa 103', 8000, '2026-11-07');
        $this->entry('Taxa 104', 5000, '2026-11-10');

        $preview = (new StatementRematch($this->pdo))->rematch($this->admin, ['condominium_id' => $this->condominium, 'dry_run' => true]);
        self::assertSame([3, 1, 2, [$late]], [$preview['pending_before'], $preview['matched'], $preview['still_pending'], array_column($preview['lines'], 'id')]);
        self::assertSame(3, $this->pending(), 'dry_run não grava.');
        self::assertStringNotContainsString('JOAO', (string)json_encode($preview));

        $done = (new StatementRematch($this->pdo))->rematch($this->admin, ['condominium_id' => $this->condominium]);
        self::assertSame(1, $done['changed']);
        $row = $this->pdo->query("SELECT status, entry_id, note, resolved_by FROM erp_bank_statement_lines WHERE id = {$late}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['matched', $paid, StatementRematch::NOTE, (int)$this->admin->id], [$row['status'], (int)$row['entry_id'], $row['note'], (int)$row['resolved_by']]);
        self::assertSame(['unmatched', 'unmatched'], [$this->lineStatus($twin), $this->lineStatus($far)], 'Dois candidatos ou fora dos 3 dias: continua pendente.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_bank_statement_lines'")->fetchColumn());
        self::assertSame(0, (new StatementRematch($this->pdo))->rematch($this->admin, ['condominium_id' => $this->condominium])['matched']);
    }

    public function testScope(): void
    {
        $limitedId = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($limitedId, $this->condominium);
        $limited = (new User())->findById($limitedId);
        try {
            (new StatementRematch($this->pdo))->rematch($limited, ['condominium_id' => $this->other]);
            self::fail('Conferiu extrato de condomínio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
        $result = (new StatementRematch($this->pdo))->rematch($this->admin, ['condominium_id' => $this->condominium, 'bank_account_id' => $this->bank, 'dry_run' => true]);
        self::assertSame([[$this->bank], 0], [array_column($result['accounts'], 'bank_account_id'), $result['pending_before']]);
    }

    private function line(string $fitid, string $postedOn, int $cents, ?string $memo = null): int
    {
        $this->pdo->prepare("INSERT INTO erp_bank_statement_lines (condominium_id, bank_account_id, import_id, fitid, kind, posted_on, amount_cents, memo, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'unmatched')")->execute([$this->condominium, $this->bank, $this->import, $fitid, $cents >= 0 ? 'credit' : 'debit', $postedOn, $cents, $memo]);
        return (int)$this->pdo->lastInsertId();
    }

    private function entry(string $description, int $cents, string $paidOn): int
    {
        return $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->account,
            'competence' => '2026-11', 'description' => $description, 'amount_cents' => number_format($cents / 100, 2, ',', ''), 'due_on' => '2026-11-10',
            'paid_on' => $paidOn, 'bank_account_id' => $this->bank])['id'];
    }

    private function pending(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_bank_statement_lines WHERE status = 'unmatched'")->fetchColumn();
    }

    private function lineStatus(int $line): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_bank_statement_lines WHERE id = {$line}")->fetchColumn();
    }

    private function condominiumNamed(string $name): int
    {
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute([$name, 'active']);
        return (int)$this->pdo->lastInsertId();
    }
}
