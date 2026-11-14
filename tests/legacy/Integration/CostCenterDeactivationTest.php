<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\CostCenterDeactivation;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0273: centros de custo parados regravados como Inativo pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class CostCenterDeactivationTest extends TestCase
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
        // Acesso a todos os condomínios, como no EntryReclassificationTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        $this->registry->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = (int)$this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Obras'])['id'];
    }

    public function testDeactivatesIdleCostCentersOnly(): void
    {
        // Datas relativas ao mês atual: 14 meses atrás fica fora da janela padrão de 12 meses; o mês atual fica dentro.
        $old = date('Y-m', strtotime('first day of -14 months'));
        $now = date('Y-m');
        $idle = $this->center('OBRA-A', 'Obra do bloco A');
        $never = $this->center('NUNCA', 'Nunca usado');
        $recent = $this->center('PORTARIA', 'Portaria');
        $open = $this->center('ABERTO', 'Conta antiga em aberto');
        $paid = $this->entry($idle, $old);
        $this->pdo->prepare('UPDATE erp_entries SET paid_on = ? WHERE id = ?')->execute([$old . '-15', $paid]);
        $this->entry($recent, $now);
        $this->entry($open, $old);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Obras encerradas'];

        $preview = (new CostCenterDeactivation($this->pdo))->deactivate($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$idle, $never], 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['cost_centers'], 'id'),
            $preview['never_used'], $preview['skipped_open']]);
        self::assertSame([$old, null], array_column($preview['cost_centers'], 'last_competence'));
        self::assertSame('active', $this->state($idle), 'dry_run não grava.');

        $done = (new CostCenterDeactivation($this->pdo))->deactivate($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'active'], [$this->state($idle), $this->state($never), $this->state($recent), $this->state($open)]);
        self::assertSame($idle, (int)$this->pdo->query("SELECT cost_center_id FROM erp_entries WHERE id = {$paid}")->fetchColumn(), 'O lançamento não muda.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_cost_centers' AND action = 'deactivate_idle'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_cost_centers' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['OBRA-A', 'Obra do bloco A', 'Nunca usado'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, (new CostCenterDeactivation($this->pdo))->deactivate($this->admin, $input)['count'], 'Já inativos não entram de novo.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $never = $this->center('NUNCA', 'Nunca usado');
        $recent = $this->center('PORTARIA', 'Portaria');
        $this->entry($recent, date('Y-m'));
        try {
            (new CostCenterDeactivation($this->pdo))->deactivate($this->admin, ['condominium_id' => $this->condominium, 'cost_center_ids' => [$never, $recent],
                'reason' => 'Obras encerradas']);
            self::fail('Centro de custo com lançamento recente entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$recent, $exception->errors['_cost_center']);
        }
        self::assertSame('active', $this->state($never), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new CostCenterDeactivation($this->pdo))->deactivate($limited, ['condominium_id' => $this->condominium, 'reason' => 'Obras encerradas']);
            self::fail('Inativação fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function center(string $code, string $name): int
    {
        return (int)$this->registry->save($this->admin, 'cost_centers', ['condominium_id' => $this->condominium, 'code' => $code, 'name' => $name])['id'];
    }

    private function entry(int $center, string $competence): int
    {
        return (int)$this->registry->save($this->admin, 'entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'chart_account_id' => $this->account,
            'cost_center_id' => $center, 'competence' => $competence, 'description' => 'Lançamento de teste', 'amount_cents' => '120,00',
            'due_on' => $competence . '-10'])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_cost_centers WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
