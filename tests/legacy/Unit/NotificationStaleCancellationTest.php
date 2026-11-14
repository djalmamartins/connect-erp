<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Source\Models\User;
use Source\Services\CondominiumAccessDenied;
use Source\Services\CondominiumScope;
use Source\Services\Erp\NotificationOutbox;
use Source\Services\Erp\NotificationStaleCancellation;
use Source\Services\Erp\Registry\RegistryValidationException;

/**
 * ADR 0281: avisos pendentes parados cancelados em lote. Unit com SQLite (Pdo\Sqlite quando existe; PDO no PHP 8.2; o PDO
 * de teste tira o FOR UPDATE e ganha NOW()) para a regra pura (limite com o sinal certo, parado pelo mais recente entre
 * criação e available_at, recusas), a validação, o escopo, as recusas (uma por caso, tudo desfeito, sem conteúdo nem erro
 * na mensagem), a prévia, o limite em dias, o cancelamento gravado só nos parados deste condomínio, sem e-mail nem
 * entrega, e o contrato. Os tipos vêm de NotificationOutbox::EVENTS. O MariaDB confere com a outbox de verdade
 * (Integration\NotificationStaleCancellationTest).
 */
final class NotificationStaleCancellationTest extends TestCase
{
    private PDO $pdo;
    private NotificationStaleCancellation $service;
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
            'CREATE TABLE erp_notification_outbox (id INTEGER PRIMARY KEY, condominium_id INTEGER, event_type TEXT, subject_table TEXT, subject_id INTEGER, payload_json TEXT,
                status TEXT, attempts INTEGER DEFAULT 0, available_at TEXT, processed_at TEXT, last_error TEXT, created_at TEXT)',
            'CREATE TABLE erp_notification_deliveries (id INTEGER PRIMARY KEY, outbox_id INTEGER, delivery_mode TEXT, recipient_masked TEXT)',
            'CREATE TABLE mail_queue (id INTEGER PRIMARY KEY, subject TEXT, recipient TEXT)'] as $sql) {
            $this->pdo->exec($sql);
        }
        $this->pdo->exec("INSERT INTO condominiums (id, name) VALUES (1, 'Aurora'), (3, 'Fora')");
        $this->pdo->exec('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (7, 1)');
        $types = NotificationOutbox::EVENTS;
        [$a, $b] = [$types[0], $types[count($types) - 1]];
        $insert = $this->pdo->prepare("INSERT INTO erp_notification_outbox (id, condominium_id, event_type, subject_table, subject_id, payload_json, status, available_at, last_error, created_at)
            VALUES (?, ?, ?, 'erp_announcements', 1, '{\"unit_label\":\"Apto 101 Fulano\"}', ?, ?, ?, ?)");
        foreach ([[1, 1, $a, 'pending', '2026-11-01 09:00:00', '2026-11-01 09:00:00'], [2, 1, $a, 'pending', '2026-11-20 09:00:00', '2026-11-01 09:00:00'],
            [3, 1, $a, 'pending', '2026-11-13 09:00:00', '2026-11-13 09:00:00'], [4, 1, $a, 'failed', '2026-11-01 09:00:00', '2026-11-01 09:00:00'],
            [5, 1, $a, 'processing', '2026-11-01 09:00:00', '2026-11-01 09:00:00'], [6, 1, $b, 'pending', null, '2026-10-01 09:00:00'],
            [7, 3, $a, 'pending', '2026-11-01 09:00:00', '2026-11-01 09:00:00']] as [$id, $c, $type, $status, $available, $created]) {
            $insert->execute([$id, $c, $type, $status, $available, $status === 'failed' ? 'Caixa cheia de fulano@exemplo.com' : null, $created]);
        }
        $this->now = new \DateTimeImmutable('2026-11-14 12:00:00');
        $this->service = new NotificationStaleCancellation($this->pdo, new CondominiumScope($this->pdo));
    }

    public function testPureRules(): void
    {
        $cutoff = NotificationStaleCancellation::cutoff($this->now, NotificationStaleCancellation::DEFAULT_DAYS);
        self::assertLessThan($this->now, $cutoff, 'O limite fica no passado.');
        self::assertSame(NotificationStaleCancellation::DEFAULT_DAYS, (int)$cutoff->diff($this->now)->days);
        self::assertTrue(NotificationStaleCancellation::stale($cutoff->format('Y-m-d H:i:s'), null, $cutoff), 'Exatamente no limite já está parado.');
        self::assertFalse(NotificationStaleCancellation::stale($cutoff->modify('+1 second')->format('Y-m-d H:i:s'), null, $cutoff));
        self::assertFalse(NotificationStaleCancellation::stale('2026-01-01 00:00:00', $this->now->format('Y-m-d H:i:s'), $cutoff), 'Agendado para depois não está parado.');
        $row = ['status' => 'pending', 'created_at' => '2026-01-01 00:00:00', 'available_at' => null];
        self::assertNull(NotificationStaleCancellation::problem($row, $cutoff, 7));
        self::assertStringContainsString('não encontrado', (string)NotificationStaleCancellation::problem(null, $cutoff, 7));
        self::assertStringContainsString('não está pendente', (string)NotificationStaleCancellation::problem(['status' => 'processing'] + $row, $cutoff, 7));
        self::assertStringContainsString('mais novo que o limite de 7 dias', (string)NotificationStaleCancellation::problem(['created_at' => '2026-11-14 00:00:00'] + $row, $cutoff, 7));
    }

    public function testValidationAndScope(): void
    {
        $ok = ['condominium_id' => 1, 'reason' => 'Fila parada desde a troca'];
        foreach ([['condominium_id' => 0] + $ok, ['reason' => 'abc'] + $ok, ['reason' => str_repeat('x', 256)] + $ok, ['days' => 0] + $ok, ['days' => 'x'] + $ok,
            ['days' => NotificationStaleCancellation::MAX_DAYS + 1] + $ok, ['event_ids' => []] + $ok, ['event_ids' => ['x']] + $ok, ['event_ids' => 'abc'] + $ok,
            ['event_ids' => range(1, NotificationStaleCancellation::MAX_EVENTS + 1)] + $ok] as $input) {
            try {
                $this->service->cancel($this->user(), $input, $this->now);
                self::fail('Entrada inválida passou: ' . json_encode($input));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $this->expectException(CondominiumAccessDenied::class);
        $this->service->cancel($this->user(), ['condominium_id' => 3] + $ok, $this->now);
    }

    public function testChosenIdsRefusedAllOrNothing(): void
    {
        foreach ([[2, 'mais novo'], [3, 'mais novo'], [4, 'não está pendente'], [5, 'não está pendente'], [7, 'não encontrado'], [99, 'não encontrado']] as [$id, $message]) {
            try {
                $this->service->cancel($this->user(), ['condominium_id' => 1, 'event_ids' => [1, $id], 'reason' => 'Fila parada desde a troca'], $this->now);
                self::fail("Aviso {$id} passou.");
            } catch (RegistryValidationException $exception) {
                self::assertSame((string)$id, $exception->errors['_event'], 'O 1 é pendente parado e passa; a recusa é a do outro.');
                self::assertStringContainsString($message, $exception->errors['_form']);
                foreach (['Fulano', 'Apto', 'exemplo', 'Caixa cheia'] as $needle) {
                    self::assertStringNotContainsString($needle, $exception->errors['_form']);
                }
            }
            self::assertFalse($this->pdo->inTransaction());
            self::assertSame(0, $this->cancelled(), 'Nada foi cancelado.');
        }
    }

    public function testDryRunDaysAndCancelWithoutIds(): void
    {
        $preview = $this->service->cancel($this->user(), ['condominium_id' => '1', 'reason' => 'Fila parada desde a troca', 'dry_run' => true], $this->now);
        self::assertSame([1, 6], array_column($preview['events'], 'id'), 'Só os pendentes parados; o agendado, o recente, o com falha, o em processamento e o de fora ficam.');
        self::assertSame([2, 0, NotificationStaleCancellation::DEFAULT_DAYS], [$preview['count'], $preview['changed'], $preview['days']]);
        self::assertSame($preview['count'], array_sum(array_column($preview['by_type'], 'events')), 'O total é a soma por tipo.');
        self::assertSame(0, $this->cancelled(), 'A prévia desfaz tudo.');
        $longer = $this->service->cancel($this->user(), ['condominium_id' => 1, 'days' => 30, 'reason' => 'Fila parada desde a troca', 'dry_run' => true], $this->now);
        self::assertSame([6], array_column($longer['events'], 'id'), 'Com 30 dias, só o de outubro.');

        $result = $this->service->cancel($this->user(), ['condominium_id' => 1, 'reason' => 'Fila parada desde a troca'], $this->now);
        self::assertSame([2, 2], [$result['count'], $result['changed']]);
        $rows = $this->pdo->query('SELECT id, status, last_error, payload_json FROM erp_notification_outbox ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $byId = array_combine(array_map('intval', array_column($rows, 'id')), $rows);
        foreach ([1, 6] as $id) {
            self::assertSame(['cancelled', NotificationStaleCancellation::SYSTEM_NOTE], [$byId[$id]['status'], $byId[$id]['last_error']]);
            self::assertStringContainsString('unit_label', $byId[$id]['payload_json'], 'O conteúdo do evento fica como está.');
        }
        self::assertSame(['pending', 'pending', 'failed', 'processing', 'pending'], [$byId[2]['status'], $byId[3]['status'], $byId[4]['status'], $byId[5]['status'], $byId[7]['status']]);
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM mail_queue')->fetchColumn(), 'Nada é enviado.');
        self::assertSame(0, (int)$this->pdo->query('SELECT COUNT(*) FROM erp_notification_deliveries')->fetchColumn());
        $json = (string)json_encode($result, JSON_UNESCAPED_UNICODE);
        foreach (['Fulano', 'Apto', 'exemplo', 'Caixa cheia', 'unit_label'] as $needle) {
            self::assertStringNotContainsString($needle, $json);
        }
    }

    public function testContract(): void
    {
        $root = dirname(__DIR__, 3);
        $service = (string)file_get_contents($root . '/source/Services/Erp/NotificationStaleCancellation.php');
        foreach ([' FOR UPDATE', "Audit::record('cancel_stale'", '$this->pdo->rollBack()', '$this->scope->assertAccess(', "AND status = 'pending'", "SELECT NOW()"] as $needle) {
            self::assertStringContainsString($needle, $service, $needle);
        }
        $body = explode('final class', $service)[1];
        foreach (['INSERT ', 'DELETE ', 'dispatch(', 'mail_queue', 'NotificationDispatcher', 'payload_json', 'recipient', 'email'] as $needle) {
            self::assertStringNotContainsString($needle, $body, 'Só cancela, sem enviar e sem destinatário ou conteúdo: ' . $needle);
        }
        $controller = (string)file_get_contents($root . '/source/Controllers/Api/V1/NotificationStaleCancellation.php');
        foreach (["Access::can('erp.access', \$user)", "Access::can('erp.notifications.view', \$user)", "Access::can('erp.notifications.manage', \$user)",
            'catch (CondominiumAccessDenied | RegistryNotFound)', "'errors' => \$exception->errors"] as $needle) {
            self::assertStringContainsString($needle, $controller, $needle);
        }
        $index = (string)file_get_contents($root . '/index.php');
        $route = '$route->post("/notifications/cancel-stale", "NotificationStaleCancellation:store", null, \Source\Support\Middleware\ApiAuthenticated::class);';
        self::assertStringContainsString($route, $index);
        self::assertGreaterThan(strpos($index, '"/reports/notification-queue"'), strpos($index, $route), 'Logo depois da saúde da fila.');
        self::assertLessThan(strpos($index, '"/notifications/{id}"'), strpos($index, $route), 'Antes das rotas /notifications/{id}.');
        $openapi = (string)file_get_contents($root . '/docs/api/openapi.yaml');
        self::assertGreaterThan(strpos($openapi, "  /reports/notification-queue:\n"), strpos($openapi, "  /notifications/cancel-stale:\n"));
    }

    private function cancelled(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM erp_notification_outbox WHERE status = 'cancelled'")->fetchColumn();
    }

    private function user(): User
    {
        $user = new User();
        $user->id = 7;
        return $user;
    }
}
