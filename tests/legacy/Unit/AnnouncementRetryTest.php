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
use Source\Services\Erp\AnnouncementRetry;
use Source\Services\Erp\NotificationDispatcher;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0279: envios com falha dos comunicados publicados de volta à fila, em lote. Unit com SQLite (Pdo\Sqlite quando
 * existe; PDO no PHP 8.2; o PDO de teste tira o FOR UPDATE e ganha NOW()) para a regra pura, a validação, o escopo, as
 * recusas (uma por caso, tudo desfeito, sem assunto nem texto na mensagem), a prévia, a volta à fila pelo
 * NotificationDispatcher::retry (outro condomínio, outra origem, agendado e cancelado de fora) sem e-mail criado e o
 * contrato. Os tipos vêm de AnnouncementBoard::KINDS. O MariaDB confere com a fila de verdade
 * (Integration\AnnouncementRetryTest).
 */
final class AnnouncementRetryTest extends TestCase
{
    private PDO $pdo;
    private AnnouncementRetry $service;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $now = static fn (): string => '2026-11-14 12:00:00';
        if (class_exists(\Pdo\Sqlite::class)) {
            $this->pdo = new class ('sqlite::memory:') extends \Pdo\Sqlite {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->createFunction('NOW', $now, 0);
        } else {
            $this->pdo = new class ('sqlite::memory:') extends PDO {
                public function prepare(string $query, array $options = []): \PDOStatement|false
                {
                    return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options);
                }
            };
            $this->pdo->sqliteCreateFunction('NOW', $now, 0);
        }
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        foreach (['CREATE TABLE access_roles (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_user_roles (user_id INTEGER, role_id INTEGER)',
            'CREATE TABLE access_permissions (id INTEGER PRIMARY KEY, slug TEXT)', 'CREATE TABLE access_role_permissions (role_id INTEGER, permission_id INTEGER)',
            'CREATE TABLE access_user_overrides (user_id INTEGER, permission_id INTEGER, effect TEXT)',
            "CREATE TABLE condominiums (id INTEGER PRIMARY KEY, name TEXT, document TEXT, status TEXT DEFAULT 'active')",
            'CREATE TABLE user_condominium_access (user_id INTEGER, condominium_id INTEGER)',
            'CREATE TABLE erp_announcements (id INTEGER PRIMARY KEY, condominium_id INTEGER, kind TEXT, subject TEXT, body TEXT, status TEXT, send_at TEXT)',
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, condominium_id INTEGER, event_type TEXT, subject_table TEXT, subject_id INTEGER, status TEXT,
                delivery_mode TEXT, recipients_count INTEGER DEFAULT 0, attempts INTEGER, available_at TEXT, processed_at TEXT, last_error TEXT, created_at TEXT)',
            'CREATE TABLE erp_notification_deliveries (id INTEGER PRIMARY KEY, outbox_id INTEGER, delivery_mode TEXT, recipient_masked TEXT, sent_to TEXT, mail_queue_id INTEGER,
                created_at TEXT)',
            'CREATE TABLE mail_queue (id INTEGER PRIMARY KEY, subject TEXT, recipient TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $kinds = array_keys(AnnouncementBoard::KINDS);
        [$first, $last] = [$kinds[0], $kinds[count($kinds) - 1]];
        $insert = $this->pdo->prepare("INSERT INTO erp_announcements (id, condominium_id, kind, subject, body, status, send_at) VALUES (?, ?, ?, 'Obra da fachada', 'Fulano, apto 101', ?, ?)");
        foreach ([[1, 1, $first, 'active', '2026-11-10 09:00:00'], [2, 1, $last, 'active', '2026-11-01 09:00:00'], [3, 1, $first, 'active', '2026-11-20 09:00:00'],
            [4, 1, $first, 'cancelled', '2026-11-01 09:00:00'], [5, 1, $first, 'active', '2026-11-05 09:00:00'], [6, 3, $first, 'active', '2026-11-05 09:00:00']] as $row) {
            $insert->execute($row);
        }
        $event = $this->pdo->prepare("INSERT INTO erp_notification_outbox (id, condominium_id, event_type, subject_table, subject_id, status, attempts, available_at, last_error)
            VALUES (?, ?, 'announcement.published', ?, ?, ?, 5, '2026-11-10 09:00:00', ?)");
        foreach ([[1, 1, 'erp_announcements', 1, 'failed'], [2, 1, 'erp_announcements', 1, 'failed'], [3, 1, 'erp_announcements', 1, 'sent'],
            [4, 1, 'erp_announcements', 2, 'failed'], [5, 1, 'erp_announcements', 3, 'failed'], [6, 1, 'erp_announcements', 4, 'failed'],
            [7, 1, 'erp_announcements', 5, 'sent'], [8, 3, 'erp_announcements', 6, 'failed'], [9, 1, 'erp_invoices', 1, 'failed']] as [$id, $c, $table, $subject, $status]) {
            $event->execute([$id, $c, $table, $subject, $status, $status === 'failed' ? 'Caixa do destinatário cheia' : null]);
        }
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $scope = new CondominiumScope($this->pdo);
        $this->service = new AnnouncementRetry($this->pdo, $scope, new NotificationDispatcher($this->pdo, 'testing', 'log', null,
            static fn (): ?int => throw new \LogicException('O lote não envia nada.')));
    }

    public function testProblemPureRule(): void
    {
        $row = ['id' => 1];
        self::assertNull(AnnouncementRetry::problem($row, 'published', 1));
        self::assertStringContainsString('não encontrado', (string)AnnouncementRetry::problem(null, null, 1));
        self::assertStringContainsString('não encontrado', (string)AnnouncementRetry::problem($row, null, 1));
        self::assertStringContainsString('cancelado', (string)AnnouncementRetry::problem($row, 'cancelled', 1));
        self::assertStringContainsString('agendado', (string)AnnouncementRetry::problem($row, 'scheduled', 1));
        self::assertStringContainsString('não tem envio com falha', (string)AnnouncementRetry::problem($row, 'published', 0));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Caixas liberadas'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('x', 256)] + $ok, ['announcement_ids' => []] + $ok,
            ['announcement_ids' => ['x']] + $ok, ['announcement_ids' => 'abc'] + $ok, ['announcement_ids' => range(1, AnnouncementRetry::MAX_ANNOUNCEMENTS + 1)] + $ok] as $input) {
            try {
                $this->service->retry($this->user(), $input, $this->now);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->retry($this->user(), ['condominium_id' => 3] + $ok, $this->now);
    }

    public function testChosenIdsRefusedAllOrNothing(): void
    {
        foreach ([[3, 'agendado'], [4, 'cancelado'], [5, 'não tem envio com falha'], [6, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->retry($this->user(), ['condominium_id' => 1, 'announcement_ids' => [1, $id], 'reason' => 'Caixas liberadas'], $this->now);
                self::fail("Comunicado {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_announcement'], 'O 1 é publicado com falha e passa; a recusa é a do outro.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Obra', 'Fulano', 'apto', 'Caixa do destinatário'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(7, $this->failedEvents(), 'Nada voltou à fila.');
        }
    }

    public function testDryRunThenRetryWithoutIds(): void
    {
        $failedBefore = $this->failedEvents();
        $preview = $this->service->retry($this->user(), ['condominium_id' => '1', 'reason' => 'Caixas liberadas', 'dry_run' => true], $this->now);
        self::assertSame([1, 2], array_column($preview['announcements'], 'id'), 'Só os publicados com falha; o agendado e o cancelado ficam de fora.');
        self::assertSame([2, 1], array_column($preview['announcements'], 'events'));
        self::assertSame([2, 3, 0], [$preview['count'], $preview['events'], $preview['changed']]);
        self::assertSame($preview['events'], array_sum(array_column($preview['announcements'], 'events')), 'O total é a soma das linhas.');
        self::assertSame($failedBefore, $this->failedEvents(), 'A prévia desfaz tudo.');
        self::assertFalse($this->pdo->inTransaction());

        $result = $this->service->retry($this->user(), ['condominium_id' => 1, 'reason' => 'Caixas liberadas'], $this->now);
        self::assertSame([2, 3, 3], [$result['count'], $result['events'], $result['changed']]);
        $rows = $this->pdo->query('SELECT id, status, attempts, last_error FROM erp_notification_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_combine(array_map('intval', array_column($rows, 'id')), $rows);
        foreach ([1, 2, 4] as $id) {
            self::assertSame(['pending', 0, null], [$byId[$id]['status'], (int)$byId[$id]['attempts'], $byId[$id]['last_error']], "Evento {$id} de volta à fila, zerado.");
        }
        foreach ([5, 6, 8, 9] as $id) {
            self::assertSame('failed', $byId[$id]['status'], "Evento {$id} não é de comunicado publicado deste condomínio.");
        }
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'O lote não cria e-mail.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn(), 'Nem entrega.');
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Obra', 'Fulano', 'apto', 'Caixa do destinatário'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testReuseContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/AnnouncementRetry.php');
        foreach (['$this->dispatcher->retry($user, ', 'AnnouncementBoard::state(', ' FOR UPDATE', "Audit::record('retry_failed'", '$this->pdo->rollBack()',
            '$this->scope->assertAccess(', "o.subject_table = 'erp_announcements'"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['UPDATE ', 'INSERT ', 'DELETE ', 'dispatch(', 'mail_queue', 'NotificationOutbox', 'queueMail', 'body', ' subject,', "'subject'", 'email'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só pelo reenvio da fila, sem enviar nem criar e-mail e sem assunto ou texto: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/AnnouncementRetry.php');
        foreach (["Access::can('erp.announcements.view', \$user)", "Access::can('erp.announcements.manage', \$user)", "Access::can('erp.notifications.manage', \$user)", 'catch (CondominiumAccessDenied | RegistryNotFound)',
            "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/announcements/retry-failed", "AnnouncementRetry:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/notice-board"'), strpos($index, $route), 'Logo depois da situação dos comunicados.');
        self::assertLessThan(strpos($index, '"/announcements/{id}"'), strpos($index, $route), 'Antes das rotas /announcements/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/notice-board:\n"), strpos($openapi, "  /announcements/retry-failed:\n"));
    }

    private function failedEvents(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE status = 'failed'")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
