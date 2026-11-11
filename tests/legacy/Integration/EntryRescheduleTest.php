<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryReschedule;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0197: a prorrogação em lote regrava só o vencimento pelo formulário, de contas em aberto, tudo ou nada. */
final class EntryRescheduleTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;
    private int $bank;

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
        $this->registry->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = $this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Obras'])['id'];
        $this->bank = $this->registry->save($this->admin, 'bank_accounts', ['condominium_id' => $this->condominium, 'name' => 'Conta 1', 'bank_code' => '341',
            'branch' => '1234', 'account_number' => '11111-1', 'kind' => 'corrente'])['id'];
    }

    public function testDryRunThenShiftAndFixedDate(): void
    {
        $a = $this->entry('Parcela 1', '2026-11-10');
        $b = $this->entry('Parcela 2', '2026-12-10');
        $input = ['condominium_id' => $this->condominium, 'entry_ids' => [$a, $b], 'days' => 15, 'reason' => 'Fornecedor aceitou nova data'];

        $preview = (new EntryReschedule($this->pdo))->reschedule($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, ['2026-11-25', '2026-12-25']], [$preview['dry_run'], $preview['changed'], array_column($preview['entries'], 'after')]);
        self::assertSame(['2026-11-10', '2026-12-10'], $this->dues(), 'dry_run não grava.');

        self::assertSame(2, (new EntryReschedule($this->pdo))->reschedule($this->admin, $input)['changed']);
        self::assertSame(['2026-11-25', '2026-12-25'], $this->dues());
        self::assertSame(['2026-11', '2026-12'], $this->pdo->query('SELECT competence FROM erp_entries ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN), 'Competência não muda.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries' AND action = 'reschedule'")->fetchColumn());

        (new EntryReschedule($this->pdo))->reschedule($this->admin, ['condominium_id' => $this->condominium, 'entry_ids' => [$a, $b], 'due_on' => '2027-01-05',
            'reason' => 'Pagamento adiado pela assembleia']);
        self::assertSame(['2027-01-05', '2027-01-05'], $this->dues());
    }

    public function testPaidChargedOrClosedRollsBackEverything(): void
    {
        $open = $this->entry('Em aberto', '2026-11-10');
        $paid = $this->entry('Paga', '2026-11-10', '2026-11-09');
        $this->assertRefused([$open, $paid], (string)$paid);

        $charged = $this->entry('Com boleto', '2026-11-10');
        $this->pdo->prepare("INSERT INTO erp_bank_charges (condominium_id, bank_account_id, entry_id, adapter, status, our_number, amount_cents, due_on)
            VALUES (?, ?, ?, 'sandbox', 'registered', '0007', 12000, '2026-11-10')")->execute([$this->condominium, $this->bank, $charged]);
        $this->assertRefused([$open, $charged], (string)$charged);

        $closed = $this->entry('Mês fechado', '2026-10-10', null, '2026-10');
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-10', 'closed')")->execute([$this->condominium]);
        $this->assertRefused([$open, $closed], (string)$closed, 'competence');
        self::assertSame('2026-11-10', $this->pdo->query("SELECT due_on FROM erp_entries WHERE id = {$open}")->fetchColumn(), 'Tudo ou nada.');
    }

    private function assertRefused(array $ids, string $entry, string $field = '_form'): void
    {
        try {
            (new EntryReschedule($this->pdo))->reschedule($this->admin, ['condominium_id' => $this->condominium, 'entry_ids' => $ids, 'days' => 10,
                'reason' => 'Prorrogação do mês']);
            self::fail('Prorrogou conta que não pode.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
            self::assertSame($entry, $exception->errors['_entry'] ?? null);
        }
    }

    /** @return list<string> */
    private function dues(): array
    {
        return $this->pdo->query('SELECT due_on FROM erp_entries ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
    }

    private function entry(string $description, string $dueOn, ?string $paidOn = null, ?string $competence = null): int
    {
        return $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'chart_account_id' => $this->account,
            'competence' => $competence ?? substr($dueOn, 0, 7), 'description' => $description, 'amount_cents' => '120,00', 'due_on' => $dueOn,
            'paid_on' => $paidOn, 'bank_account_id' => $paidOn === null ? null : $this->bank])['id'];
    }
}
