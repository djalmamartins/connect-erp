<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCompletion;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0208: conclusão pelo formulário e a próxima periódica agendada, sem duplicar, tudo ou nada. */
final class MaintenanceCompletionTest extends TestCase
{
    private const TODAY = '2026-11-11';

    private int $condominium;
    private User $admin;
    private RegistryService $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        // Acesso a todos os condomínios: o cadastro de fornecedores é comum (ADR 0202).
        $slug = 'role_' . $id;
        $this->pdo->prepare('INSERT INTO access_roles (name, slug, level) VALUES (?, ?, 50)')->execute([$slug, $slug]);
        $role = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$id, $role]);
        $this->pdo->prepare("INSERT IGNORE INTO access_permissions (name, slug, group_name) VALUES ('Todos', ?, 'ERP')")->execute([CondominiumScope::ALL_PERMISSION]);
        $permission = (int)$this->pdo->query('SELECT id FROM access_permissions WHERE slug = ' . $this->pdo->quote(CondominiumScope::ALL_PERMISSION))->fetchColumn();
        $this->pdo->prepare('INSERT INTO access_role_permissions (role_id, permission_id) VALUES (?, ?)')->execute([$role, $permission]);
        $this->admin = (new User())->findById($id);
    }

    public function testCompleteSchedulesNextWithoutDuplicating(): void
    {
        $tank = $this->maintenance("Limpeza da caixa d'água", '2026-11-10', 'semestral');
        $lift = $this->maintenance('Revisão do elevador', '2026-10-31', 'mensal');
        $paint = $this->maintenance('Pintura da fachada', '2026-11-01', 'unica');
        $already = $this->maintenance('Revisão do elevador', '2026-11-30', 'mensal');
        $input = ['condominium_id' => $this->condominium, 'items' => [['id' => $tank, 'done_on' => '2026-11-10'], ['id' => $lift, 'done_on' => '2026-11-05'],
            ['id' => $paint, 'done_on' => '2026-11-01']]];

        $preview = (new MaintenanceCompletion($this->pdo))->complete($this->admin, $input + ['dry_run' => true], self::TODAY);
        self::assertSame([true, 0, 3, 1, 1], [$preview['dry_run'], $preview['changed'], $preview['completed'], $preview['scheduled'], $preview['already_scheduled']]);
        self::assertSame([['2027-05-10', null, false], ['2026-11-30', $already, true], null], array_map(static fn (array $m): ?array => $m['next'] === null ? null
            : [$m['next']['planned_on'], $m['next']['id'], $m['next']['already_scheduled']], $preview['maintenances']));
        self::assertSame([4, ['active', 'active', 'active', 'active']], [$this->maintenanceCount(), $this->statuses()], 'dry_run não grava.');

        $done = (new MaintenanceCompletion($this->pdo))->complete($this->admin, $input, self::TODAY);
        self::assertSame([3, 1, 4], [$done['completed'], $done['scheduled'], $done['changed']]);
        self::assertSame(['done', 'done', 'done', 'active', 'active'], $this->statuses());
        $next = $this->pdo->query("SELECT service, planned_on, frequency, done_on, status FROM erp_maintenances WHERE id = {$done['maintenances'][0]['next']['id']}")->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(["Limpeza da caixa d'água", '2027-05-10', 'semestral', null, 'active'], [$next['service'], substr((string)$next['planned_on'], 0, 10),
            $next['frequency'], $next['done_on'], $next['status']]);
        self::assertSame('2026-11-05', substr((string)$this->pdo->query("SELECT done_on FROM erp_maintenances WHERE id = {$lift}")->fetchColumn(), 0, 10));
        self::assertSame(3, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_maintenances' AND action = 'complete'")->fetchColumn());

        $this->assertRefused([['id' => $tank, 'done_on' => '2026-11-10']], (string)$tank, 'Manutenção já concluída.');
    }

    public function testRefusalRollsBackEverything(): void
    {
        $plain = $this->maintenance('Recarga dos extintores', '2026-11-05', 'anual');
        $supplier = $this->registry->save($this->admin, 'suppliers', ['legal_name' => 'Elevadores Teste Ltda', 'document' => '11222333000181', 'category' => 'elevadores'])['id'];
        $withSupplier = $this->maintenance('Revisão do elevador', '2026-11-05', 'mensal', $supplier);
        $this->pdo->exec("UPDATE erp_suppliers SET status = 'inactive' WHERE id = {$supplier}");
        try {
            (new MaintenanceCompletion($this->pdo))->complete($this->admin, ['condominium_id' => $this->condominium,
                'items' => [['id' => $plain, 'done_on' => '2026-11-05'], ['id' => $withSupplier, 'done_on' => '2026-11-05']]], self::TODAY);
            self::fail('Concluiu com fornecedor inativo.');
        } catch (RegistryValidationException $exception) {
            self::assertArrayHasKey('supplier_id', $exception->errors, json_encode($exception->errors, JSON_UNESCAPED_UNICODE));
            self::assertSame((string)$withSupplier, $exception->errors['_maintenance'] ?? null);
        }
        self::assertSame([2, ['active', 'active']], [$this->maintenanceCount(), $this->statuses()], 'Tudo ou nada: o extintor também não foi concluído nem agendado.');

        $cancelled = $this->maintenance('Dedetização', '2026-11-01', 'trimestral');
        $this->pdo->exec("UPDATE erp_maintenances SET status = 'inactive' WHERE id = {$cancelled}");
        $this->assertRefused([['id' => $plain, 'done_on' => '2026-11-05'], ['id' => $cancelled, 'done_on' => '2026-11-05']], (string)$cancelled, 'Manutenção cancelada.');
        $this->assertRefused([['id' => 999999, 'done_on' => '2026-11-05']], '999999', 'Manutenção não encontrada neste condomínio.');
        self::assertSame(0, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_maintenances' AND action = 'complete'")->fetchColumn());
    }

    private function assertRefused(array $items, string $maintenance, string $message): void
    {
        try {
            (new MaintenanceCompletion($this->pdo))->complete($this->admin, ['condominium_id' => $this->condominium, 'items' => $items], self::TODAY);
            self::fail('Concluiu manutenção que não pode.');
        } catch (RegistryValidationException $exception) {
            self::assertSame([$message, $maintenance], [$exception->errors['_form'] ?? null, $exception->errors['_maintenance'] ?? null]);
        }
    }

    private function maintenanceCount(): int
    {
        return (int)$this->pdo->query('SELECT COUNT(*) FROM erp_maintenances')->fetchColumn();
    }

    /** @return list<string> */
    private function statuses(): array
    {
        return array_map('strval', $this->pdo->query('SELECT status FROM erp_maintenances ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function maintenance(string $service, string $plannedOn, string $frequency, ?int $supplier = null): int
    {
        return $this->registry->save($this->admin, 'maintenances', ['condominium_id' => $this->condominium, 'service' => $service, 'planned_on' => $plannedOn,
            'frequency' => $frequency, 'supplier_id' => $supplier])['id'];
    }
}
