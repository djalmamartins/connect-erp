<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\NotificationQueue;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\NotificationQueueHealth;

/** ADR 0280: saúde da fila de avisos com eventos gravados pela outbox de verdade; somente leitura e sem destinatário, conteúdo nem erro. */
final class NotificationQueueHealthTest extends TestCase
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

    public function testHealthFromRealOutboxEvents(): void
    {
        $types = NotificationOutbox::EVENTS;
        $outbox = new NotificationOutbox($this->pdo);
        foreach ([[$types[0], 'erp_announcements', 1], [$types[0], 'erp_announcements', 2], [$types[count($types) - 1], 'erp_invoices', 3]] as [$type, $table, $subject]) {
            self::assertTrue($outbox->record($type, "health-test:{$type}:{$subject}:" . bin2hex(random_bytes(4)), $this->condominium, $table, $subject,
                ['unit_label' => 'Apto 101 Fulano'], (int)$this->admin->id));
        }
        $ids = array_map('intval', $this->pdo->query("SELECT id FROM erp_notification_outbox WHERE condominium_id = {$this->condominium} ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
        $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'failed', attempts = 4, last_error = 'Caixa cheia de fulano@exemplo.com' WHERE id = ?")->execute([$ids[1]]);
        $this->pdo->prepare("UPDATE erp_notification_outbox SET status = 'sent', attempts = 1, delivery_mode = 'log' WHERE id = ?")->execute([$ids[2]]);
        $mode = array_keys(NotificationQueue::MODES)[0];
        $this->pdo->prepare("INSERT INTO erp_notification_deliveries (outbox_id, delivery_mode, recipient_hash, recipient_masked) VALUES (?, ?, ?, 'f***@exemplo.com')")
            ->execute([$ids[2], $mode, hash('sha256', 'fulano@exemplo.com')]);
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new NotificationQueueHealth($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame($types, array_column($report['rows'], 'event_type'), 'Os tipos vêm da outbox.');
        self::assertSame([3, 1, 1, 1], [$report['totals']['events'], $report['totals']['pending'], $report['totals']['failed'], $report['totals']['sent']]);
        self::assertSame(1, array_sum($report['pending_by_age']), 'O pendente recém-criado espera há pouco.');
        self::assertSame(1, $report['pending_by_age'][array_key_first(NotificationQueueHealth::AGES)]);
        self::assertSame(['failed' => 1, 'average' => 4.0, 'max' => 4], $report['attempts']);
        self::assertSame([$ids[1]], $report['failed_ids']);
        self::assertSame([$ids[0]], $report['oldest_pending_ids']);
        self::assertSame([['subject_table' => 'erp_announcements', 'events' => 2], ['subject_table' => 'erp_invoices', 'events' => 1]], $report['subjects']);
        self::assertSame(1, $report['deliveries']['total']);
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['exemplo', 'Fulano', 'Apto', 'Caixa cheia', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new NotificationQueueHealth($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }
}
