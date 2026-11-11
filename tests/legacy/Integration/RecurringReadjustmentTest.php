<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringEntries;
use Source\Services\Erp\RecurringReadjustment;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0204: o reajuste regrava só o valor dos lançamentos fixos pelo formulário, tudo ou nada; o já gerado não muda. */
final class RecurringReadjustmentTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;

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
        $this->account = $this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Portaria'])['id'];
    }

    public function testDryRunThenReadjustKeepsGeneratedEntries(): void
    {
        $portaria = $this->template('Portaria', '100,00');
        $limpeza = $this->template('Limpeza', '123,45');
        $generated = (new RecurringEntries($this->pdo))->generate($this->admin, '2027-01', $this->condominium)['created'];
        self::assertCount(2, $generated);
        $input = ['condominium_id' => $this->condominium, 'recurring_ids' => [$portaria, $limpeza], 'percent' => '4,62', 'from_competence' => '2027-01',
            'reason' => 'Reajuste anual pelo IPCA'];

        $preview = (new RecurringReadjustment($this->pdo))->readjust($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 22345, 23377], [$preview['dry_run'], $preview['changed'], $preview['before_cents'], $preview['after_cents']]);
        self::assertSame([10462, 12915], array_column($preview['templates'], 'after_cents'));
        self::assertSame([['count' => 1, 'cents' => 10000], ['count' => 1, 'cents' => 12345]], array_column($preview['templates'], 'already_generated'));
        self::assertSame(['already_generated'], array_column($preview['warnings'], 'code'));
        self::assertSame(['10000', '12345'], $this->amounts('erp_recurring_entries'), 'dry_run não grava.');

        $done = (new RecurringReadjustment($this->pdo))->readjust($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['10462', '12915'], $this->amounts('erp_recurring_entries'));
        self::assertSame(['10000', '12345'], $this->amounts('erp_entries'), 'O já gerado não muda.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_recurring_entries' AND action = 'readjust'")->fetchColumn());

        $next = (new RecurringEntries($this->pdo))->generate($this->admin, '2027-02', $this->condominium)['created'];
        self::assertSame(['10462', '12915'], array_map(fn (int $id): string => (string)$this->pdo->query("SELECT amount_cents FROM erp_entries WHERE id = {$id}")->fetchColumn(), $next),
            'O próximo mês nasce com o valor novo.');
    }

    public function testInactiveOrEndedRollsBackEverything(): void
    {
        $open = $this->template('Portaria', '100,00');
        $inactive = $this->template('Jardinagem', '50,00');
        $this->pdo->exec("UPDATE erp_recurring_entries SET status = 'inactive' WHERE id = {$inactive}");
        $this->assertRefused([$open, $inactive], (string)$inactive, 'Lançamento fixo inativo: reative antes de reajustar.');

        $ended = $this->template('Obra', '80,00', '2026-12');
        $this->assertRefused([$open, $ended], (string)$ended, 'Lançamento fixo encerrado em 2026-12, antes da competência do reajuste.');
        $this->assertRefused([$open, 999999], '999999', 'Lançamento fixo não encontrado neste condomínio.');
        self::assertSame('10000', (string)$this->pdo->query("SELECT amount_cents FROM erp_recurring_entries WHERE id = {$open}")->fetchColumn(), 'Tudo ou nada.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_recurring_entries' AND action = 'readjust'")->fetchColumn());
    }

    private function assertRefused(array $ids, string $template, string $message): void
    {
        try {
            (new RecurringReadjustment($this->pdo))->readjust($this->admin, ['condominium_id' => $this->condominium, 'recurring_ids' => $ids, 'percent' => '10',
                'from_competence' => '2027-01', 'reason' => 'Reajuste do contrato']);
            self::fail('Reajustou lançamento fixo que não pode.');
        } catch (RegistryValidationException $exception) {
            self::assertSame([$message, $template], [$exception->errors['_form'] ?? null, $exception->errors['_recurring'] ?? null],
                json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return list<string> */
    private function amounts(string $table): array
    {
        return array_map('strval', $this->pdo->query("SELECT amount_cents FROM {$table} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function template(string $description, string $amount, ?string $ends = null): int
    {
        return $this->registry->save($this->admin, 'recurring_entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'description' => $description,
            'amount_cents' => $amount, 'chart_account_id' => $this->account, 'day_of_month' => '10', 'starts_competence' => '2026-01', 'ends_competence' => $ends])['id'];
    }
}
