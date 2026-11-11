<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\PaymentLocks;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0193: o estorno conta a conta pelo formulário (paid_on para vazio) recusa conta conciliada com o extrato e conta
 * com boleto pago pelo banco, com a mesma mensagem do estorno em lote (ADR 0192). Mudar a data da baixa continua livre.
 */
final class EntryReversalLocksTest extends TestCase
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

    public function testReconciledEntryCannotBeReopenedButDateCanChange(): void
    {
        $entry = $this->paidEntry();
        $this->pdo->prepare("INSERT INTO erp_imports (condominium_id, kind, original_name, sha256, storage_path, size_bytes, status) VALUES (?, 'ofx', 'extrato.ofx', ?, 'x.ofx', 10, 'applied')")
            ->execute([$this->condominium, str_repeat('a', 64)]);
        $import = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO erp_bank_statement_lines (condominium_id, bank_account_id, import_id, fitid, kind, posted_on, amount_cents, status, entry_id)
            VALUES (?, ?, ?, 'F1', 'credit', '2026-11-09', 15000, 'matched', ?)")->execute([$this->condominium, $this->bank, $import, $entry]);

        $this->assertLocked($entry, PaymentLocks::RECONCILED);
        $moved = $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '2026-11-08', 'bank_account_id' => $this->bank]), $entry);
        self::assertSame('2026-11-08', $moved['paid_on'], 'Corrigir a data da baixa não é estorno.');
    }

    public function testBankConfirmedEntryCannotBeReopened(): void
    {
        $entry = $this->paidEntry();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on, paid_cents, paid_on)
            VALUES (?, ?, ?, 'sandbox', 'paid', '0001', 15000, '2026-11-10', 15000, '2026-11-09')")->execute([$this->condominium, $this->bank, $entry]);
        $this->assertLocked($entry, PaymentLocks::BANK_CONFIRMED);
    }

    public function testPlainPaidEntryStillReopens(): void
    {
        $entry = $this->paidEntry();
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on)
            VALUES (?, ?, ?, 'sandbox', 'cancelled', '0002', 15000, '2026-11-10')")->execute([$this->condominium, $this->bank, $entry]);
        $open = $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '', 'bank_account_id' => $this->bank]), $entry);
        self::assertNull($open['paid_on'], 'Sem conciliação nem boleto pago, o estorno pelo formulário continua como antes.');
    }

    private function assertLocked(int $entry, string $message): void
    {
        try {
            $this->service->save($this->admin, 'entries', $this->input(['paid_on' => '', 'bank_account_id' => $this->bank]), $entry);
            self::fail('Estornou conta travada pelo banco.');
        } catch (RegistryValidationException $exception) {
            self::assertSame($message, $exception->errors['paid_on'] ?? null, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
        }
        self::assertNotNull($this->pdo->query('SELECT paid_on FROM erp_entries WHERE id = ' . $entry)->fetchColumn() ?: null, 'Continua paga.');
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
