<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\Registry\RegistryService;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Services\Erp\WaitlistExpiry;

/** ADR 0237: inscrições vencidas da lista de espera baixadas pelo cadastro, tudo ou nada, com auditoria e nenhum aviso. */
final class WaitlistExpiryTest extends TestCase
{
    private int $condominium;
    private User $admin;
    private RegistryService $registry;
    private int $unit;
    /** @var array<string, int> */
    private array $areas = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $this->registry = new RegistryService($this->pdo);
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
        $this->unit = $this->registry->save($this->admin, 'units', ['condominium_id' => $this->condominium, 'number' => '101', 'type' => 'apartamento'])['id'];
        foreach (['Salão de festas', 'Quadra'] as $name) {
            $this->pdo->prepare("INSERT INTO erp_common_areas (condominium_id, name, booking_mode) VALUES (?, ?, 'approval')")->execute([$this->condominium, $name]);
            $this->areas[$name] = (int)$this->pdo->lastInsertId();
        }
    }

    public function testExpiresPastWaitingEntriesOnly(): void
    {
        $older = $this->entry('Salão de festas', -20);
        $old = $this->entry('Salão de festas', -10);
        $current = $this->entry('Salão de festas', 5);
        $served = $this->entry('Salão de festas', -15);
        $orphan = $this->entry('Quadra', -12);
        $this->pdo->prepare("UPDATE erp_waitlist SET status = 'served' WHERE id = ?")->execute([$served]);
        $this->pdo->prepare('UPDATE erp_waitlist SET called_at = NOW() WHERE id = ?')->execute([$older]);
        $this->pdo->prepare("UPDATE erp_common_areas SET status = 'inactive' WHERE id = ?")->execute([$this->areas['Quadra']]);
        $outbox = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn();
        $mails = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $notices = $this->notices();
        $input = ['condominium_id' => $this->condominium, 'reason' => 'Data passou sem vaga'];

        $preview = (new WaitlistExpiry($this->pdo))->expire($this->admin, $input + ['dry_run' => true]);
        self::assertSame([true, 0, [$older, $old], [true, false], 1], [$preview['dry_run'], $preview['changed'], array_column($preview['entries'], 'id'),
            array_column($preview['entries'], 'called'), $preview['skipped_inactive_link']]);
        self::assertSame('active', $this->state($older), 'dry_run não grava.');

        $done = (new WaitlistExpiry($this->pdo))->expire($this->admin, $input);
        self::assertSame(2, $done['changed']);
        self::assertSame(['inactive', 'inactive', 'active', 'served', 'active'], [$this->state($older), $this->state($old), $this->state($current),
            $this->state($served), $this->state($orphan)]);
        self::assertSame('Morador de teste', $this->pdo->query("SELECT resident_name FROM erp_waitlist WHERE id = {$old}")->fetchColumn(), 'Dados preservados.');
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_waitlist' AND action = 'expire'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_waitlist' AND action = 'update'")->fetchColumn(),
            'A auditoria update do formulário também fica.');
        self::assertSame([$outbox, $mails, $notices], [(int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_outbox')->fetchColumn(),
            (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), $this->notices()], 'Nenhum aviso (a notificação interna da auditoria fica de fora).');
        self::assertStringNotContainsString('Morador de teste', (string)json_encode($done, JSON_UNESCAPED_UNICODE));
    }

    public function testChosenIdsAreAllOrNothingAndScope(): void
    {
        $old = $this->entry('Salão de festas', -10);
        $current = $this->entry('Salão de festas', 5);
        try {
            (new WaitlistExpiry($this->pdo))->expire($this->admin, ['condominium_id' => $this->condominium, 'entry_ids' => [$old, $current],
                'reason' => 'Data passou sem vaga']);
            self::fail('Inscrição ainda válida entrou na baixa.');
        } catch (RegistryValidationException $exception) {
            self::assertSame((string)$current, $exception->errors['_entry']);
        }
        self::assertSame('active', $this->state($old), 'Nada gravado.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new WaitlistExpiry($this->pdo))->expire($limited, ['condominium_id' => $this->condominium, 'reason' => 'Data passou sem vaga']);
            self::fail('Baixa fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function entry(string $area, int $days): int
    {
        return (int)$this->registry->save($this->admin, 'waitlist', ['condominium_id' => $this->condominium, 'common_area_id' => $this->areas[$area],
            'unit_id' => $this->unit, 'resident_name' => 'Morador de teste', 'desired_on' => (new \DateTimeImmutable("{$days} days"))->format('Y-m-d')])['id'];
    }

    private function state(int $id): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_waitlist WHERE id = {$id}")->fetchColumn();
    }

    private function notices(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM notifications WHERE title <> 'Alteração no sistema'")->fetchColumn();
    }
}
