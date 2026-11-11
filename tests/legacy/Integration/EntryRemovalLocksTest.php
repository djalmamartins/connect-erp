<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PaymentLocks;
use Source\Services\Erp\Registry\RegistryPaymentLocked;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0194: o formulário não exclui (deleted_at) nem cancela (status inactive) conta conciliada com o extrato ou com
 * boleto pago pelo banco. Conta sem essas travas continua sendo cancelada e excluída como antes.
 */
final class EntryRemovalLocksTest extends TestCase
{
    private int $condominium;
    private RegistryService $service;
    private User $admin;
    private int $account;
    private int $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->service = new RegistryService($this->pdo);
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
        $this->service->save($this->admin, 'chart', ['code' => '1', 'nature' => 'receita', 'kind' => 'sintetica', 'name' => 'Receitas']);
        $this->account = $this->service->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->bank = $this->service->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Conta 1', 'bank_code' => '341',
            'branch' => '1234', 'account_number' => '11111-1', 'kind' => 'corrente'])['id'];
    }

    public function testReconciledEntryIsNeitherCancelledNorDeleted(): void
    {
        $entry = $this->paidEntry();
        $this->pdo->prepare("INSERT INTO erp_imports (condominium_id, kind, original_name, sha256, storage_path, size_bytes, status) VALUES (?, 'ofx', 'extrato.ofx', ?, 'x.ofx', 10, 'applied')")
            ->execute([$this->condominium, str_repeat('b', 64)]);
        $import = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_bank_statement_lines (condominium_id, bank_account_id, import_id, fitid, kind, posted_on, amount_cents, status, entry_id)
            VALUES (?, ?, ?, 'F9', 'credit', '2026-11-09', 15000, 'matched', ?)")->execute([$this->condominium, $this->bank, $import, $entry]);
        $this->assertLocked($entry, 'reconciled', PaymentLocks::RECONCILED_REMOVAL);
    }

    public function testBankConfirmedEntryIsNeitherCancelledNorDeleted(): void
    {
        $entry = $this->paidEntry();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, paid_cents, paid_on)
            VALUES (?, ?, ?, 'sandbox', 'paid', '0009', 15000, '2026-11-10', 15000, '2026-11-09')")->execute([$this->condominium, $this->bank, $entry]);
        $this->assertLocked($entry, 'bank_confirmed', PaymentLocks::BANK_CONFIRMED_REMOVAL);
    }

    public function testPlainEntryIsCancelledAndDeletedAsBefore(): void
    {
        $entry = $this->paidEntry();
        $cancelled = $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '2026-11-09', 'bank_account_id' => $this->bank, 'status' => 'inactive']), $entry);
        self::assertSame('inactive', $cancelled['status']);
        $this->service->delete($this->admin, 'entries', $entry);
        self::assertNotNull($this->pdo->query('SELECT deleted_at FROM erp_entries WHERE id = ' . $entry)->fetchColumn() ?: null);
    }

    private function assertLocked(int $entry, string $reason, string $message): void
    {
        try {
            $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '2026-11-09', 'bank_account_id' => $this->bank, 'status' => 'inactive']), $entry);
            self::fail('Cancelou conta travada pelo banco.');
        } catch (RegistryValidationException $exception) {
            self::assertSame($message, $exception->errors['_form'] ?? null, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
        }
        try {
            $this->service->delete($this->admin, 'entries', $entry);
            self::fail('Excluiu conta travada pelo banco.');
        } catch (RegistryPaymentLocked $exception) {
            self::assertSame([$reason, $message], [$exception->reason, $exception->getMessage()]);
        }
        $row = $this->pdo->query('SELECT status, deleted_at FROM erp_entries WHERE id = ' . $entry)->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['active', null], [$row['status'], $row['deleted_at']], 'Continua válida e não excluída.');
        $edited = $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '2026-11-09', 'bank_account_id' => $this->bank, 'description' => 'Taxa (nota corrigida)']), $entry);
        self::assertSame('Taxa (nota corrigida)', $edited['description'], 'Editar outros campos continua livre.');
    }

    private function paidEntry(): int
    {
        return $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '2026-11-09', 'bank_account_id' => $this->bank]))['id'];
    }

    private function input(array $override): array
    {
        return $override + ['condominium_id' => $this->condominium, 'nature' => 'receita', 'chart_account_id' => $this->account, 'competence' => '2026-11',
            'description' => 'Taxa de teste', 'amount_cents' => '150,00', 'due_on' => '2026-11-10'];
    }
}
