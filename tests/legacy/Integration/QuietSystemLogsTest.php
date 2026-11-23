<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Support\AppLogger;
use Source\Support\Audit;

/** ADR 0300 e 0645: rotina sem usuário não cria aviso "Alteração no sistema" e o mesmo erro aberto não abre um registro a cada 5 minutos. */
final class QuietSystemLogsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createUser(['level' => 10]);
        $this->pdo->exec("INSERT INTO notifications_categories (title, uri, description, type) VALUES ('Studio', 'studio', 'Avisos', 'system')");
        unset($_SESSION['authUser']);
    }

    protected function tearDown(): void
    {
        unset($_SESSION['authUser']);
        parent::tearDown();
    }

    public function testRoutineWithoutUserIsAuditedButDoesNotNotify(): void
    {
        Audit::record('update', 'erp_notification_outbox', 615, ['status' => 'processing'], ['status' => 'sent']);

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_notification_outbox' AND entity_id = 615 AND users_id IS NULL"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM notifications WHERE title = 'Alteração no sistema'"));
    }

    public function testChangeByAnotherPersonIsAuditedButDoesNotNotify(): void
    {
        $_SESSION['authUser'] = $this->createUser(['level' => 1]);

        Audit::record('update', 'erp_units', 7, ['number' => '101'], ['number' => '102']);

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM system_audit_logs WHERE entity = 'erp_units' AND entity_id = 7"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM notifications WHERE title = 'Alteração no sistema'"), 'ADR 0645: auditoria não vai ao sininho.');
    }

    public function testNewErrorIsLoggedButDoesNotNotifyNorEmail(): void
    {
        $developer = $this->createUser(['level' => 1, 'email' => 'dev.' . bin2hex(random_bytes(3)) . '@connect.lab']);
        $this->pdo->exec("INSERT IGNORE INTO access_roles (slug, name) VALUES ('developer', 'Desenvolvedor')");
        $role = (int)$this->pdo->query("SELECT id FROM access_roles WHERE slug = 'developer'")->fetchColumn();
        $this->pdo->prepare('INSERT IGNORE INTO access_user_roles (user_id, role_id) VALUES (?, ?)')->execute([$developer, $role]);
        $message = 'Falha de teste ' . bin2hex(random_bytes(3));

        AppLogger::log('error', $message, ['event_type' => 'test_failure'], 'tests');

        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM app_log WHERE msg = " . $this->pdo->quote($message) . " AND status = 'open'"));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM notifications WHERE title = 'Erro detectado no MovesOS'"));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM mail_queue WHERE source_log_id IS NOT NULL'));
    }

    public function testSameOpenErrorAfterFiveMinutesAddsAnOccurrence(): void
    {
        $message = 'Permissão não cadastrada negada (teste ' . bin2hex(random_bytes(3)) . ')';
        $first = AppLogger::log('warning', $message, ['event_type' => 'permission_unregistered', 'permission' => 'erp.teste.view'], 'authorization');
        $this->pdo->prepare('UPDATE app_log SET last_seen_at = DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE msg = ?')->execute([$message]);

        $second = AppLogger::log('warning', $message, ['event_type' => 'permission_unregistered', 'permission' => 'erp.teste.view'], 'authorization');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) AS rows_count, MAX(occurrences) AS occurrences FROM app_log WHERE msg = ?');
        $stmt->execute([$message]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        self::assertSame([1, 2], [(int)$row['rows_count'], (int)$row['occurrences']]);
        self::assertSame($first, $second, 'Mesmo incidente.');
    }

    public function testSameErrorAfterADayOpensANewIncident(): void
    {
        $message = 'Erro antigo (teste ' . bin2hex(random_bytes(3)) . ')';
        AppLogger::log('warning', $message, ['event_type' => 'test_event'], 'authorization');
        $this->pdo->prepare('UPDATE app_log SET last_seen_at = DATE_SUB(NOW(), INTERVAL 25 HOUR) WHERE msg = ?')->execute([$message]);

        AppLogger::log('warning', $message, ['event_type' => 'test_event'], 'authorization');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM app_log WHERE msg = ?');
        $stmt->execute([$message]);
        self::assertSame(2, (int)$stmt->fetchColumn());
    }

    private function countRows(string $sql): int
    {
        return (int)$this->pdo->query($sql)->fetchColumn();
    }
}
