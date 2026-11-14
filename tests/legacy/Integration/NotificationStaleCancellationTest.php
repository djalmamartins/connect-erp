<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\NotificationStaleCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0281: avisos pendentes parados cancelados com a outbox de verdade; auditado, sem e-mail nem entrega e reversível pelo reenvio da fila. */
final class NotificationStaleCancellationTest extends TestCase
{
    private int $condominium;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $this->condominium = (int)$this->pdo->lastInsertId();
        $id = $this->createUser(['level' => 2]);
        (new CondominiumScope($this->pdo))->grant($id, $this->condominium);
        $this->admin = (new User())->findById($id);
    }

    public function testCancelsStalePendingEvents(): void
    {
        $type = NotificationOutbox::EVENTS[0];
        $old = $this->event($type, 10);
        $recent = $this->event($type, 1);
        $older = $this->event(NotificationOutbox::EVENTS[count(NotificationOutbox::EVENTS) - 1], 40);
        $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'failed' WHERE id = ?")->execute([$older]);
        $mail = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $deliveries = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn();
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
        $service = new NotificationStaleCancellation($this->pdo);
        $reason = ['condominium_id' => $this->condominium, 'reason' => 'Fila parada desde a troca'];

        $preview = $service->cancel($this->admin, $reason + ['dry_run' => true]);
        self::assertSame([[$old], 0], [array_column($preview['events'], 'id'), $preview['changed']]);
        self::assertSame('pending', $this->eventStatus($old), 'A prévia não cancela.');
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn());

        foreach ([[$recent, 'mais novo'], [$older, 'não está pendente']] as [$event, $message]) {
            try {
                $service->cancel($this->admin, $reason + ['event_ids' => [$old, $event]]);
                self::fail("Aviso {$event} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$event, $exception->errors['_event']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertSame('pending', $this->eventStatus($old), 'Tudo ou nada.');
        }

        $result = $service->cancel($this->admin, $reason);
        self::assertSame([1, 1], [$result['count'], $result['changed']]);
        self::assertSame('cancelled', $this->eventStatus($old));
        self::assertSame('pending', $this->eventStatus($recent));
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_notification_outbox' AND action = 'cancel_stale' AND entity_id = {$old}")->fetchColumn());
        self::assertSame($mail, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'Nada é enviado.');
        self::assertSame($deliveries, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn());
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Apto', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        self::assertSame(0, $service->cancel($this->admin, $reason)['count'], 'Rodar de novo não muda nada.');

        (new NotificationDispatcher($this->pdo))->retry($this->admin, $old);
        self::assertSame('pending', $this->eventStatus($old), 'O reenvio da fila volta o cancelado, se for preciso.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $service->cancel($limited, $reason);
            self::fail('Cancelamento fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function eventStatus(int $event): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_notification_outbox WHERE id = {$event}")->fetchColumn();
    }

    /** Grava pela outbox e envelhece a criação e o available_at em $days dias (relógio do banco). */
    private function event(string $type, int $days): int
    {
        (new NotificationOutbox($this->pdo))->record($type, 'stale-test:' . bin2hex(random_bytes(6)), $this->condominium, 'erp_announcements', 1,
            ['unit_label' => 'Apto 101 Fulano'], (int)$this->admin->id);
        $id = (int)$this->pdo->query("SELECT MAX(id) FROM erp_notification_outbox WHERE condominium_id = {$this->condominium}")->fetchColumn();
        $this->pdo->prepare('UPDATE erp_notification_outbox SET created_at = DATE_SUB(NOW(), INTERVAL ? DAY), available_at = DATE_SUB(NOW(), INTERVAL ? DAY) WHERE id = ?')
            ->execute([$days, $days, $id]);
        return $id;
    }
}
