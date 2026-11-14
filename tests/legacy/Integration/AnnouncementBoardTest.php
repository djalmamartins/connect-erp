<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AnnouncementBoard;
use Source\Services\Erp\Announcements;

/** ADR 0278: situação dos comunicados com a outbox e um cancelamento de verdade; somente leitura e sem assunto nem texto. */
final class AnnouncementBoardTest extends TestCase
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

    public function testBoardFromRealAnnouncements(): void
    {
        $kinds = array_keys(AnnouncementBoard::KINDS);
        $now = new \DateTimeImmutable();
        $recent = $this->announcement($kinds[0], $now->modify('-2 days'));
        $old = $this->announcement($kinds[count($kinds) - 1], $now->modify('-40 days'));
        $scheduled = $this->announcement($kinds[0], $now->modify('+3 days'));
        $later = $this->announcement($kinds[0], $now->modify('+5 days'));
        foreach ([[$recent, 'sent'], [$recent, 'sent'], [$recent, 'failed'], [$old, 'sent'], [$scheduled, 'pending'], [$later, 'pending']] as [$announcement, $status]) {
            $this->event($announcement, $status);
        }
        (new Announcements($this->pdo))->cancel($this->admin, $later, 'Data mudou, sai outro comunicado');
        $before = (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn();

        $report = (new AnnouncementBoard($this->pdo))->build($this->admin, $this->condominium);
        self::assertSame($kinds, array_column($report['rows'], 'kind'), 'Os tipos vêm de KINDS.');
        self::assertSame([4, 2, 1, 1], [$report['totals']['announcements'], $report['totals']['published'], $report['totals']['scheduled'], $report['totals']['cancelled']]);
        $expected = array_fill_keys(array_keys(AnnouncementBoard::AGES), 0);
        $expected[AnnouncementBoard::age(2)]++;
        $expected[AnnouncementBoard::age(40)]++;
        self::assertSame($expected, $report['by_age']);
        self::assertSame([3, 1, 0], [$report['delivery']['sent'], $report['delivery']['failed'], $report['delivery']['pending']],
            'Só os publicados: o agendado e o cancelado ficam de fora dos envios.');
        self::assertSame([$recent], $report['delivery']['failed_ids']);
        self::assertSame([$scheduled], $report['scheduled_ids']);
        self::assertSame('cancelled', $this->pdo->query("SELECT status FROM erp_notification_outbox WHERE subject_table = 'erp_announcements' AND subject_id = {$later}")->fetchColumn(),
            'O cancelamento de verdade parou o evento.');
        self::assertSame($before, (int)$this->pdo->query('SELECT COUNT(*) FROM system_audit_logs')->fetchColumn(), 'Somente leitura.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Assembleia', 'Texto do comunicado', 'Data mudou'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }

        $limited = (new User())->findById($this->createUser(['level' => 2]));
        try {
            (new AnnouncementBoard($this->pdo))->build($limited, $this->condominium);
            self::fail('Relatório fora do escopo.');
        } catch (CondominiumAccessDenied) {
            self::assertTrue(true);
        }
    }

    private function announcement(string $kind, \DateTimeImmutable $sendAt): int
    {
        $this->pdo->prepare("INSERT INTO erp_announcements (condominium_id, kind, subject, body, audience, send_at, units_count, status, created_by)
            VALUES (?, ?, 'Assembleia de dezembro', 'Texto do comunicado', 'all', ?, 1, 'active', ?)")
            ->execute([$this->condominium, $kind, $sendAt->format('Y-m-d H:i:s'), $this->admin->id]);
        return (int)$this->pdo->lastInsertId();
    }

    private function event(int $announcement, string $status): void
    {
        $this->pdo->prepare("INSERT INTO erp_notification_outbox (condominium_id, event_type, event_key, subject_table, subject_id, payload_json, status)
            VALUES (?, 'announcement.published', ?, 'erp_announcements', ?, '{}', ?)")
            ->execute([$this->condominium, 'board-test-' . bin2hex(random_bytes(6)), $announcement, $status]);
    }
}
