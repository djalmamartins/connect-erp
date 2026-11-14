<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\MaintenanceCancellation;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0261: manutenções agendadas e esquecidas canceladas pelo cadastro, tudo ou nada, sem agendar a próxima, com auditoria e nenhum aviso. */
final class MaintenanceCancellationTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    /** @var array<string, int> */
    private array $suppliers = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        foreach (['ativo' => '11222333000181', 'inativo' => '11444777000161'] as $key => $document) {
            $this->pdo->prepare("INSERT INTO erp_suppliers (legal_name, document, category) VALUES ('Fornecedor de teste', ?, 'servicos')")->execute([$document]);
            $this->suppliers[$key] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testCancelsOverdueScheduledMaintenancesOnly(): void
    {
        $oldest = $this->maintenance(-300, 'unica', 'ativo');
        $old = $this->maintenance(-120, 'mensal', null);
        $recent = $this->maintenance(-20, 'mensal', null);
        $running = $this->maintenance(-200, 'unica', null);
        $this->pdo->prepare("UPDATE erp_maintenances SET status = 'running' WHERE id = ?")->execute([$running]);
        $orphan = $this->maintenance(-150, 'unica', 'inativo');
        $this->pdo->prepare("UPDATE erp_suppliers SET status = 'inactive' WHERE id = ?")->execute([$this->suppliers['inativo']]);
        $total = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_maintenances')->fetchColumn();
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Serviço duplicado'];

        $preview = (new MaintenanceCancellation($this->pdo))->cancel($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$oldest, $old], [300, 120], 1, 1], [$preview['dry_run'], $preview['changed'], array_column($preview['maintenances'], 'id'),
            array_column($preview['maintenances'], 'days_overdue'), $preview['periodic'], $preview['skipped_inactive_link']]);
        self::assertSame('active', $this->state($oldest), 'dry_run não grava.');

        $done = (new MaintenanceCancellation($this->pdo))->cancel($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'running', 'active'], [$this->state($oldest), $this->state($old), $this->state($recent), $this->state($running),
            $this->state($orphan)]);
        self::assertSame($total, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_maintenances')->fetchColumn(), 'Nenhuma próxima agendada.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_maintenances' AND action = 'cancel_overdue'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_maintenances' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        $json = (string)json_encode($done, JSON_UNESCAPED_UNICODE);
        foreach (['Manutenção de teste', 'Fornecedor de teste'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->maintenance(-120, 'unica', null);
        $recent = $this->maintenance(-20, 'unica', null);
        try {
            (new MaintenanceCancellation($this->pdo))->cancel($this->admin, ['condominium_id' => $this->condominium, 'maintenance_ids' => [$old, $recent],
                'reason' => 'Serviço duplicado']);
            self::fail('Manutenção dentro do prazo entrou.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$recent, $exception->errors['_maintenance']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new MaintenanceCancellation($this->pdo))->cancel($limited, ['condominium_id' => $this->condominium, 'reason' => 'Serviço duplicado']);
            self::fail('Cancelamento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function maintenance(int $days, string $frequency, ?string $supplier): int
    {
        return (int)$this->registry->save($this->admin, 'maintenances', ['condominium_id' => $this->condominium, 'service' => 'Manutenção de teste',
            'planned_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d'), 'frequency' => $frequency,
            'supplier_id' => $supplier !== null ? $this->suppliers[$supplier] : null])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_maintenances WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
