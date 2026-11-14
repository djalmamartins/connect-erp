<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\NotificationQueue;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\NotificationQueueHealth;

/**
 * ADR 0280: saúde da fila de avisos, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura (eventos por tipo e situação, espera dos pendentes, tentativas dos com falha, ids, origens, entregas por modo,
 * totais iguais à soma), a montagem sem destinatário, conteúdo nem erro (outro condomínio de fora), a validação, o escopo
 * e o contrato. Tipos, situações, modos e faixas vêm das próprias constantes (NotificationOutbox::EVENTS e
 * NotificationQueue::STATUSES e MODES): nada de quantidade nem lista fixa no teste. O MariaDB confere com a fila de
 * verdade (Integration\NotificationQueueHealthTest).
 */
final class NotificationQueueHealthTest extends TestCase
{
    private PDO $pdo;
    private NotificationQueueHealth $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->pdo = class_exists(\Pdo\Sqlite::class) ? new \Pdo\Sqlite('sqlite::memory:') : new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, condominium_id INTEGER, event_type TEXT, subject_table TEXT, subject_id INTEGER, payload_json TEXT,
                status TEXT, attempts INTEGER, last_error TEXT, created_at TEXT)',
            'CREATE TABLE erp_notification_deliveries (id INTEGER PRIMARY KEY, outbox_id INTEGER, delivery_mode TEXT, recipient_masked TEXT, sent_to TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        [$a, $b] = self::types();
        $insert = $this->pdo->prepare("INSERT INTO erp_notification_outbox (id, condominium_id, event_type, subject_table, subject_id, payload_json, status, attempts, last_error, created_at)
            VALUES (?, ?, ?, ?, 1, '{\"unit_label\":\"Apto 101 Fulano\"}', ?, ?, ?, ?)");
        foreach ([[1, 1, $a, 'erp_announcements', 'pending', 0, '2026-11-14 11:30:00'], [2, 1, $a, 'erp_announcements', 'processing', 1, '2026-11-14 02:00:00'],
            [3, 1, $b, 'erp_invoices', 'pending', 0, '2026-11-10 12:00:00'], [4, 1, $b, 'erp_invoices', 'failed', 5, '2026-11-09 12:00:00'],
            [5, 1, $a, 'erp_announcements', 'failed', 2, '2026-11-08 12:00:00'], [6, 1, $a, 'erp_announcements', 'sent', 1, '2026-11-08 12:00:00'],
            [7, 1, 'tipo.desconhecido', 'erp_outro', 'pending', 0, '2026-11-14 11:00:00'], [8, 3, $a, 'erp_announcements', 'failed', 5, '2026-11-01 12:00:00']]
            as [$id, $c, $type, $table, $status, $attempts, $created]) {
            $insert->execute([$id, $c, $type, $table, $status, $attempts, $status === 'failed' ? 'Caixa cheia de fulano@exemplo.com' : null, $created]);
        }
        $modes = array_keys(NotificationQueue::MODES);
        $delivery = $this->pdo->prepare("INSERT INTO erp_notification_deliveries (outbox_id, delivery_mode, recipient_masked, sent_to) VALUES (?, ?, 'f***@exemplo.com', 'teste@exemplo.com')");
        foreach ([[6, $modes[0]], [6, $modes[0]], [8, $modes[count($modes) - 1]]] as $row) {
            $delivery->execute($row);
        }
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->service = new NotificationQueueHealth($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos da outbox */
    private static function types(): array
    {
        self::assertGreaterThanOrEqual(2, count(NotificationOutbox::EVENTS));
        return [NotificationOutbox::EVENTS[0], NotificationOutbox::EVENTS[count(NotificationOutbox::EVENTS) - 1]];
    }

    public function testAgeBucketsComeFromTheConstant(): void
    {
        $ages = array_keys(NotificationQueueHealth::AGES);
        self::assertSame($ages[0], NotificationQueueHealth::age(0));
        foreach (NotificationQueueHealth::AGES as $age => $limit) {
            if ($limit !== null) {
                self::assertSame($age, NotificationQueueHealth::age($limit), "{$limit} minutos ainda é {$age}.");
                self::assertNotSame($age, NotificationQueueHealth::age($limit + 1), "{$limit} + 1 minutos já passa de {$age}.");
            }
        }
        self::assertSame(array_key_last(NotificationQueueHealth::AGES), NotificationQueueHealth::age(10000000));
        foreach (NotificationQueueHealth::WAITING as $status) {
            self::assertArrayHasKey($status, NotificationQueue::STATUSES, 'As situações de espera são da fila.');
        }
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$a, $b] = self::types();
        $report = $this->service->build($this->user(), '1', $this->now);
        self::assertSame(NotificationOutbox::EVENTS, array_column($report['rows'], 'event_type'), 'Todos os tipos da outbox, sempre.');
        self::assertSame(array_merge(['events'], array_keys(NotificationQueue::STATUSES)), array_keys($report['totals']), 'Todas as situações da fila, sempre.');
        $rows = array_combine(array_column($report['rows'], 'event_type'), $report['rows']);
        self::assertSame([4, 1, 1, 1, 1], [$rows[$a]['events'], $rows[$a]['pending'], $rows[$a]['processing'], $rows[$a]['sent'], $rows[$a]['failed']]);
        self::assertSame([2, 1, 1], [$rows[$b]['events'], $rows[$b]['pending'], $rows[$b]['failed']]);
        foreach (array_keys($report['totals']) as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['events'], array_sum(array_intersect_key($row, NotificationQueue::STATUSES)), 'As situações somam os eventos da linha.');
        }
        self::assertSame(1, $report['unknown_events'], 'O tipo fora da lista fica fora das linhas.');
        $expected = array_fill_keys(array_keys(NotificationQueueHealth::AGES), 0);
        foreach ([30, 600, 5760] as $minutes) {   // 11:30, 02:00 e 10/11 até 14/11 12:00
            $expected[NotificationQueueHealth::age($minutes)]++;
        }
        self::assertSame($expected, $report['pending_by_age']);
        self::assertSame($report['totals']['pending'] + $report['totals']['processing'], array_sum($report['pending_by_age']));
        self::assertSame(['failed' => 2, 'average' => 3.5, 'max' => 5], $report['attempts']);
        self::assertSame([[4, 5], false], [$report['failed_ids'], $report['failed_truncated']]);
        self::assertSame([[3, 2, 1], false], [$report['oldest_pending_ids'], $report['pending_truncated']], 'Do que espera há mais tempo.');
        self::assertSame([['subject_table' => 'erp_announcements', 'events' => 4], ['subject_table' => 'erp_invoices', 'events' => 2]], $report['subjects']);
        self::assertSame($report['totals']['events'], array_sum(array_column($report['subjects'], 'events')));
        self::assertSame(array_keys(NotificationQueue::MODES), array_column($report['deliveries']['rows'], 'mode'), 'Todos os modos, sempre.');
        self::assertSame([2, 2], [$report['deliveries']['rows'][0]['deliveries'], $report['deliveries']['total']], 'A entrega do outro condomínio fica de fora.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['exemplo', 'Fulano', 'Apto', 'Caixa cheia', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCutAndEmptyQueue(): void
    {
        $many = [];
        for ($i = 1; $i <= NotificationQueueHealth::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'event_type' => self::types()[0], 'subject_table' => 'erp_x', 'status' => 'failed', 'attempts' => 1, 'created_at' => '2026-11-14 10:00:00'];
        }
        $report = NotificationQueueHealth::summarize($many, [], $this->now);
        self::assertCount(NotificationQueueHealth::MAX_IDS, $report['failed_ids']);
        self::assertTrue($report['failed_truncated']);
        $empty = NotificationQueueHealth::summarize([], [], $this->now);
        self::assertSame(array_fill_keys(array_keys($empty['totals']), 0), $empty['totals']);
        self::assertSame(['failed' => 0, 'average' => 0.0, 'max' => 0], $empty['attempts']);
        self::assertSame(count(NotificationOutbox::EVENTS), count($empty['rows']), 'Fila vazia: as linhas aparecem zeradas.');
    }

    public function testValidationAndScope(): void
    {
        foreach ([null, '', 0, 'abc', '-1', []] as $value) {
            try {
                $this->service->build($this->user(), $value, $this->now);
                self::fail('Condomínio inválido passou: ' . json_encode($value));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->build($this->user(), 3, $this->now);
    }

    public function testReadOnlyContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/NotificationQueueHealth.php');
        foreach (['$this->scope->assertAccess(', 'NotificationOutbox::EVENTS', 'NotificationQueue::STATUSES', 'NotificationQueue::MODES'] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'retry(', 'dispatch(', 'mail_queue', 'payload_json', 'last_error', 'recipient', 'sent_to', 'email'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura, sem envio e sem destinatário, conteúdo ou erro: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/NotificationQueueHealth.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.notifications.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/notification-queue", "NotificationQueueHealth:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/announcements/retry-failed"'), strpos($index, $route), 'Logo depois do reenvio em lote dos comunicados.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /announcements/retry-failed:\n"), strpos($openapi, "  /reports/notification-queue:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
