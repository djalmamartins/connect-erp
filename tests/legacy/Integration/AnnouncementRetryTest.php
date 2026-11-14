<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AnnouncementBoard;
use Source\Services\Erp\AnnouncementRetry;
use Source\Services\Erp\Announcements;
use Source\Services\Erp\Registry\RegistryValidationException;

/** ADR 0279: envios com falha de volta à fila com a outbox, a auditoria e um cancelamento de verdade; nenhum e-mail criado pelo lote. */
final class AnnouncementRetryTest extends TestCase
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

    public function testRetriesFailedSendsOfPublishedAnnouncements(): void
    {
        $kinds = array_keys(AnnouncementBoard::KINDS);
        $now = new \DateTimeImmutable();
        $published = $this->announcement($kinds[0], $now->modify('-2 days'));
        $scheduled = $this->announcement($kinds[0], $now->modify('+3 days'));
        $cancelled = $this->announcement($kinds[count($kinds) - 1], $now->modify('+4 days'));
        $failedA = $this->event($published, 'failed');
        $failedB = $this->event($published, 'failed');
        $sent = $this->event($published, 'sent');
        $this->event($scheduled, 'failed');
        $this->event($cancelled, 'pending');
        (new Announcements($this->pdo))->cancel($this->admin, $cancelled, 'Data mudou, sai outro comunicado');
        $mail = (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn();
        $deliveries = (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn();
        $audits = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();
        $service = new AnnouncementRetry($this->pdo);
        $reason = ['condominium_id' => $this->condominium, 'reason' => 'Caixas de e-mail liberadas'];

        $preview = $service->retry($this->admin, $reason + ['dry_run' => true]);
        self::assertSame([[$published], 2, 0], [array_column($preview['announcements'], 'id'), $preview['events'], $preview['changed']]);
        self::assertSame('failed', $this->eventStatus($failedA), 'A prévia não reenfileira.');
        self::assertSame($audits, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'A prévia desfaz a auditoria do reenvio.');

        foreach ([[$scheduled, 'agendado'], [$cancelled, 'cancelado']] as [$announcement, $message]) {
            try {
                $service->retry($this->admin, $reason + ['announcement_ids' => [$published, $announcement]]);
                self::fail("Comunicado {$announcement} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$announcement, $exception->errors['_announcement']);
                self::assertStringContainsString($message, $exception->errors['_form']);
            }
            self::assertSame('failed', $this->eventStatus($failedA), 'Tudo ou nada.');
        }

        $result = $service->retry($this->admin, $reason);
        self::assertSame([1, 2, 2], [$result['count'], $result['events'], $result['changed']]);
        foreach ([$failedA, $failedB] as $event) {
            $row = $this->pdo->query("SELECT status, attempts, last_error FROM erp_notification_outbox WHERE id = {$event}")->fetch(\PDO::FETCH_ASSOC);
            self::assertSame(['pending', 0, null], [$row['status'], (int)$row['attempts'], $row['last_error']]);
        }
        self::assertSame('sent', $this->eventStatus($sent), 'O já enviado fica como está.');
        self::assertSame(1, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_announcements' AND action = 'retry_failed'")->fetchColumn());
        self::assertSame(2, (int)$this->pdo->query("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_notification_outbox' AND entity_id IN ({$failedA}, {$failedB})")->fetchColumn(),
            'Cada evento com a auditoria do reenvio da fila.');
        self::assertSame($mail, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'O lote não cria e-mail: quem envia é o worker da fila.');
        self::assertSame($deliveries, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn());
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Assembleia', 'Texto do comunicado', 'Caixa cheia'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
        $again = $service->retry($this->admin, $reason);
        self::assertSame(0, $again['count'], 'Rodar de novo não muda nada: não há mais falha.');

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            $service->retry($limited, $reason);
            self::fail('Reenvio fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function eventStatus(int $event): string
    {
        return (string)$this->pdo->query("SELECT status FROM erp_notification_outbox WHERE id = {$event}")->fetchColumn();
    }

    private function announcement(string $kind, \DateTimeImmutable $sendAt): int
    {
        $this->pdo->prepare("INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at, units_count, status, created_by)
            VALUES (?, ?, 'Assembleia de dezembro', 'Texto do comunicado', 'all', ?, 1, 'active', ?)")
            ->execute([$this->condominium, $kind, $sendAt->format('Y-m-d H:i:s'), $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function event(int $announcement, string $status): int
    {
        $this->pdo->prepare("INSERT INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json, status, attempts, last_error)
            VALUES (?, 'announcement.published', ?, 'erp_announcements', ?, '{}', ?, ?, ?)")
            ->execute([$this->condominium, 'retry-test-' . bin2hex(random_bytes(6)), $announcement, $status, $status === 'failed' ? 5 : 0, $status === 'failed' ? 'Caixa cheia' : null]);
        return (int)$this->pdo->lastInsertId();
    }
}
