<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\EntryReclassification;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0196: a reclassificação em lote regrava pelo formulário, só com a conta e/ou o centro de custo trocados, tudo ou nada. */
final class EntryReclassificationTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $cleaning;
    private int $works;
    private int $fee;
    private int $center;

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
        $this->fee = $this->registry->save($this->admin, 'chart', ['code' => '1.01', 'nature' => 'receita', 'kind' => 'analitica', 'name' => 'Taxa'])['id'];
        $this->cleaning = $this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Limpeza'])['id'];
        $this->works = $this->registry->save($this->admin, 'chart', ['code' => '2.02', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Obras'])['id'];
        $this->center = $this->registry->save($this->admin, 'cost_centers', ['condominium_id' => $this->condominium, 'code' => 'CC1', 'name' => 'Bloco A'])['id'];
    }

    public function testDryRunThenReclassify(): void
    {
        $a = $this->entry('Material de obra', '2026-11');
        $b = $this->entry('Pedreiro', '2026-11', '150,00');
        $input = ['condominium_id' => $this->condominium, 'entry_ids' => [$a, $b], 'chart_account_id' => $this->works, 'cost_center_id' => $this->center,
            'reason' => 'Revisão do mês pelo contador'];

        $preview = (new EntryReclassification($this->pdo))->reclassify($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, 2], [$preview['dry_run'], $preview['changed'], $preview['count']]);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE chart_account_id = ' . $this->cleaning)->fetchColumn(), 'dry_run não grava.');

        $done = (new EntryReclassification($this->pdo))->reclassify($this->admin, $input);
        self::assertSame(2, $done['changed']);
        $rows = $this->pdo->query('SELECT chart_account_id, cost_center_id, amount_cents, description, paid_on FROM erp_entries ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame([[$this->works, $this->center, 12000, 'Material de obra'], [$this->works, $this->center, 15000, 'Pedreiro']],
            array_map(static fn (array $r): array => [(int)$r['chart_account_id'], (int)$r['cost_center_id'], (int)$r['amount_cents'], $r['description']], $rows),
            'Só conta e centro de custo mudam; valor e descrição ficam.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_entries' AND action = 'reclassify'")->fetchColumn());

        (new EntryReclassification($this->pdo))->reclassify($this->admin, ['condominium_id' => $this->condominium, 'entry_ids' => [$a], 'cost_center_id' => 0,
            'reason' => 'Centro de custo errado']);
        self::assertNull($this->pdo->query("SELECT cost_center_id FROM erp_entries WHERE id = {$a}")->fetchColumn() ?: null, '0 tira o centro de custo.');
    }

    public function testClosedCompetenceOrWrongNatureRollsBackEverything(): void
    {
        $open = $this->entry('Mês aberto', '2026-11');
        $closed = $this->entry('Mês que será fechado', '2026-10');
        $this->pdo->prepare("INSERT INTO erp_month_closings (condominium_id, competence, status) VALUES (?, '2026-10', 'closed')")->execute([$this->condominium]);
        $this->assertRefused([$open, $closed], $this->works, 'competence', (string)$closed);
        $this->assertRefused([$open], $this->fee, 'chart_account_id', (string)$open);
        self::assertSame(2, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_entries WHERE chart_account_id = ' . $this->cleaning)->fetchColumn(), 'Tudo ou nada.');
    }

    private function assertRefused(array $ids, int $account, string $field, string $entry): void
    {
        try {
            (new EntryReclassification($this->pdo))->reclassify($this->admin, ['condominium_id' => $this->condominium, 'entry_ids' => $ids, 'chart_account_id' => $account,
                'reason' => 'Revisão do mês']);
            self::fail('Reclassificou lançamento que o formulário recusa.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey($field, $exception->errors, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
            self::assertSame($entry, $exception->errors['_entry'] ?? null);
        }
    }

    private function entry(string $description, string $competence, string $amount = '120,00'): int
    {
        return $this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'chart_account_id' => $this->cleaning,
            'competence' => $competence, 'description' => $description, 'amount_cents' => $amount, 'due_on' => $competence . '-10'])['id'];
    }
}
