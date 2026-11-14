<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\RecurringClosing;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0271: lançamentos fixos encerrados regravados como Inativo pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class RecurringClosingTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $account;
    private int $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        // Acesso a todos os condomínios, como no RecurringReadjustmentTest: o plano de contas padrão (sem condomínio) exige.
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
        $this->registry->save($this->admin, 'chart', ['code' => '2', 'nature' => 'despesa', 'kind' => 'sintetica', 'name' => 'Despesas']);
        $this->account = (int)$this->registry->save($this->admin, 'chart', ['code' => '2.01', 'nature' => 'despesa', 'kind' => 'analitica', 'name' => 'Portaria'])['id'];
        $this->supplier = (int)$this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Portaria de Teste Ltda', 'document' => '11222333000181',
            'category' => 'limpeza'])['id'];
    }

    public function testClosesEndedTemplatesOnly(): void
    {
        $ended = $this->template('Portaria de teste', '1234,56', date('Y-m', strtotime('first day of -2 months')), $this->supplier);
        $open = $this->template('Limpeza de teste', '100,00', null, null);
        $current = $this->template('Elevador de teste', '50,00', date('Y-m'), null);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Contrato da portaria trocado'];

        $preview = (new RecurringClosing($this->pdo))->close($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$ended], 123456, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['recurring'], 'id'),
            $preview['monthly_cents']['despesa'], $preview['suppliers_freed']]);
        self::assertSame('active', $this->state($ended), 'dry_run não grava.');

        $done = (new RecurringClosing($this->pdo))->close($this->admin, $input);
        self::assertSame(1, $done['changed']);
        self::assertSame(['inactive', 'active', 'active'], [$this->state($ended), $this->state($open), $this->state($current)]);
        self::assertSame('123456', (string)$this->pdo->query("SELECT amount_cents FROM erp_recurring_entries WHERE id = {$ended}")->fetchColumn(), 'O valor não muda.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_recurring_entries' AND action = 'close_ended'")->fetchColumn());
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_recurring_entries' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Portaria de teste', 'Portaria de Teste Ltda', '11222333000181', 'description'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, (new RecurringClosing($this->pdo))->close($this->admin, $input)['count'], 'Já encerrados não entram de novo.');
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $ended = $this->template('Portaria de teste', '100,00', date('Y-m', strtotime('first day of -2 months')), null);
        $open = $this->template('Limpeza de teste', '100,00', null, null);
        try {
            (new RecurringClosing($this->pdo))->close($this->admin, ['condominium_id' => $this->condominium, 'recurring_ids' => [$ended, $open], 'reason' => 'Contratos encerrados']);
            self::fail('Lançamento fixo sem fim entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$open, $exception->errors['_recurring']);
        }
        self::assertSame('active', $this->state($ended), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new RecurringClosing($this->pdo))->close($limited, ['condominium_id' => $this->condominium, 'reason' => 'Contratos encerrados']);
            self::fail('Encerramento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function template(string $description, string $amount, ?string $ends, ?int $supplier): int
    {
        return (int)$this->registry->save($this->admin, 'recurring_entries', ['condominium_id' => $this->condominium, 'nature' => 'despesa', 'description' => $description,
            'amount_cents' => $amount, 'chart_account_id' => $this->account, 'supplier_id' => $supplier, 'day_of_month' => '10',
            'starts_competence' => date('Y-m', strtotime('first day of -12 months')), 'ends_competence' => $ends])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_recurring_entries WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
