<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\AnnouncementBoard;

/**
 * ADR 0278: situação dos comunicados, somente leitura. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2) para a
 * regra pura (publicados, agendados e cancelados por tipo, idade dos publicados, envios da outbox, ids dos agendados e
 * dos com falha, totais iguais à soma), a montagem sem assunto nem texto (outro condomínio e outra origem da outbox de
 * fora), a validação, o escopo e o contrato. Tipos, faixas e estados vêm das próprias constantes, e as constantes batem
 * com as enums da migration; nada de quantidade nem lista fixa no teste. O MariaDB confere com comunicados de verdade
 * (Integration\AnnouncementBoardTest).
 */
final class AnnouncementBoardTest extends TestCase
{
    private PDO $pdo;
    private AnnouncementBoard $service;
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
            'CREATE TABLE erp_announcements (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, subject TEXT, body TEXT, status TEXT, send_at TEXT)',
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, subject_table TEXT, subject_id INTEGER, status TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        [$first, $last] = self::kinds();
        $insert = $this->pdo->prepare("INSERT INTO erp_announcements (id, condominium_id, kind, subject, body, status, send_at) VALUES (?, ?, ?, 'Obra da fachada do bloco A', 'Fulano, apto 101', ?, ?)");
        foreach ([[1, 1, $first, 'active', '2026-11-14 11:00:00'], [2, 1, $first, 'active', '2026-09-01 10:00:00'], [3, 1, $last, 'active', '2026-01-01 08:00:00'],
            [4, 1, $first, 'active', '2026-11-20 09:00:00'], [5, 1, $last, 'active', '2026-11-15 09:00:00'], [6, 1, $first, 'cancelled', '2026-11-20 09:00:00'],
            [7, 3, $first, 'active', '2026-11-01 09:00:00']] as $row) {
            $insert->execute($row);
        }
        $event = $this->pdo->prepare('INSERT INTO erp_notification_outbox (subject_table, subject_id, status) VALUES (?, ?, ?)');
        foreach ([[1, 'sent', 3], [1, 'failed', 1], [3, 'failed', 2], [6, 'cancelled', 4], [7, 'failed', 1]] as [$id, $status, $n]) {
            for ($i = 0; $i < $n; $i++) {
                $event->execute(['erp_announcements', $id, $status]);
            }
        }
        $event->execute(['erp_invoices', 2, 'failed']);   // outra origem com o mesmo id: fica de fora
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->service = new AnnouncementBoard($this->pdo, new CondominiumScope($this->pdo));
    }

    /** @return array{0:string, 1:string} o primeiro e o último tipo, vindos da constante */
    private static function kinds(): array
    {
        $kinds = array_keys(AnnouncementBoard::KINDS);
        self::assertGreaterThanOrEqual(2, count($kinds));
        return [$kinds[0], $kinds[count($kinds) - 1]];
    }

    public function testConstantsMatchTheMigrationEnums(): void
    {
        $sql = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0537_announcements.sql');
        preg_match("/`kind` enum\\(([^)]*)\\)/", $sql, $kind);
        self::assertSame(array_keys(AnnouncementBoard::KINDS), array_map('trim', explode(',', str_replace("'", '', $kind[1]))));
        $outbox = (string)file_get_contents(dirname(__DIR__, 3) . '/database/migrations/20261108_0532_notification_outbox.sql');
        preg_match("/`status` enum\\(([^)]*)\\)/", $outbox, $status);
        self::assertSame(AnnouncementBoard::DELIVERY, array_map('trim', explode(',', str_replace("'", '', $status[1]))));
    }

    public function testAgeBucketsComeFromTheConstant(): void
    {
        $ages = array_keys(AnnouncementBoard::AGES);
        self::assertSame($ages[0], AnnouncementBoard::age(0));
        foreach (AnnouncementBoard::AGES as $age => $limit) {
            if ($limit !== null) {
                self::assertSame($age, AnnouncementBoard::age($limit), "{$limit} dias ainda é {$age}.");
                self::assertNotSame($age, AnnouncementBoard::age($limit + 1), "{$limit} + 1 dias já passa de {$age}.");
            }
        }
        self::assertSame(array_key_last(AnnouncementBoard::AGES), AnnouncementBoard::age(100000));
    }

    public function testSummaryFromTheCondominium(): void
    {
        [$first, $last] = self::kinds();
        $report = $this->service->build($this->user(), '1', $this->now);
        self::assertSame(array_keys(AnnouncementBoard::KINDS), array_column($report['rows'], 'kind'), 'Todos os tipos, sempre.');
        $rows = array_combine(array_column($report['rows'], 'kind'), $report['rows']);
        self::assertSame([4, 2, 1, 1], [$rows[$first]['announcements'], $rows[$first]['published'], $rows[$first]['scheduled'], $rows[$first]['cancelled']]);
        self::assertSame([2, 1, 1, 0], [$rows[$last]['announcements'], $rows[$last]['published'], $rows[$last]['scheduled'], $rows[$last]['cancelled']]);
        foreach (array_keys($report['totals']) as $key) {
            self::assertSame(array_sum(array_column($report['rows'], $key)), $report['totals'][$key], "Total de {$key} é a soma das linhas.");
        }
        foreach ($report['rows'] as $row) {
            self::assertSame($row['announcements'], $row['published'] + $row['scheduled'] + $row['cancelled']);
        }
        $expected = array_fill_keys(array_keys(AnnouncementBoard::AGES), 0);
        foreach ([0, 74, 317] as $days) {   // 14/11, 01/09 e 01/01 até 14/11/2026
            $expected[AnnouncementBoard::age($days)]++;
        }
        self::assertSame($expected, $report['by_age']);
        self::assertSame($report['totals']['published'], array_sum($report['by_age']));
        self::assertSame(AnnouncementBoard::DELIVERY, array_slice(array_keys($report['delivery']), 0, count(AnnouncementBoard::DELIVERY)), 'Todos os estados da outbox, sempre.');
        self::assertSame([3, 3, 0], [$report['delivery']['sent'], $report['delivery']['failed'], $report['delivery']['cancelled']],
            'Só os publicados: os eventos do cancelado, do outro condomínio e de outra origem ficam de fora.');
        self::assertSame([[1, 3], false], [$report['delivery']['failed_ids'], $report['delivery']['failed_truncated']]);
        self::assertSame([[5, 4], false], [$report['scheduled_ids'], $report['scheduled_truncated']], 'Do mais próximo ao mais distante.');
        $json = (string)json_encode($report, JSON_UNESCAPED_UNICODE);
        foreach (['Obra', 'fachada', 'Fulano', 'apto'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testIdsAreCut(): void
    {
        $many = [];
        for ($i = 1; $i <= AnnouncementBoard::MAX_IDS + 1; $i++) {
            $many[] = ['id' => $i, 'kind' => self::kinds()[0], 'status' => 'active', 'send_at' => '2026-12-01 09:00:00'];
        }
        $report = AnnouncementBoard::summarize($many, [], $this->now);
        self::assertCount(AnnouncementBoard::MAX_IDS, $report['scheduled_ids']);
        self::assertTrue($report['scheduled_truncated']);
        self::assertSame(AnnouncementBoard::MAX_IDS + 1, $report['totals']['scheduled']);
        $empty = AnnouncementBoard::summarize([], [], $this->now);
        self::assertSame(array_fill_keys(array_keys($empty['totals']), 0), $empty['totals']);
        self::assertSame(count(AnnouncementBoard::KINDS), count($empty['rows']), 'Sem comunicado, as linhas aparecem zeradas.');
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
        $service = (string)file_get_contents($root . '/source/Services/Erp/AnnouncementBoard.php');
        self::assertStringContainsString('$this->scope->assertAccess(', $service);
        self::assertStringContainsString("o.subject_table = 'erp_announcements'", $service);
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'UPDATE ', 'DELETE ', 'Audit::', 'NotificationOutbox', 'NotificationDispatcher', 'mail_queue', 'body', ' subject,', "'subject'",
            'email', 'erp_residents', 'erp_units'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Somente leitura, sem envio e sem assunto, texto ou destinatário: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AnnouncementBoard.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.announcements.view', \$user)", 'catch (CondominiumAccessDenied)'] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->get("/reports/notice-board", "AnnouncementBoard:show", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/documents/restore-linked"'), strpos($index, $route), 'Logo depois da restauração do Disco Virtual.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /documents/restore-linked:\n"), strpos($openapi, "  /reports/notice-board:\n"));
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
