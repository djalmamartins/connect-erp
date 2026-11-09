<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\NotificationQueue;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Tela da fila de avisos (CN-005, ADR 0055 e 0072): lista com situação, trilha mascarada e reenvio
 * manual com CSRF, pelo NotificationDispatcher.
 */
final class ErpNotificationQueueScreenTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testDetailNeverShowsAnAddressInClear(): void
    {
        $detail = NotificationQueue::maskDetail(['id' => 1, 'deliveries' => [
            ['delivery_mode' => 'sandbox', 'recipient_masked' => 'a***@x.com', 'sent_to' => 'caixa@teste.lab', 'mail_queue_id' => 3, 'created_at' => null],
            ['delivery_mode' => 'log', 'recipient_masked' => 'b***@x.com', 'sent_to' => null, 'mail_queue_id' => null, 'created_at' => null],
        ]]);
        self::assertSame('c****@teste.lab', $detail['deliveries'][0]['sent_to']);
        self::assertNull($detail['deliveries'][1]['sent_to']);
        self::assertSame('a***@x.com', $detail['deliveries'][0]['recipient_masked']);
    }

    public function testStatusesMatchTheOutbox(): void
    {
        $migration = $this->file('database/migrations/20261108_0532_notification_outbox.sql');
        foreach (array_keys(NotificationQueue::STATUSES) as $status) {
            self::assertStringContainsString("'{$status}'", $migration);
        }
        self::assertSame(['log', 'sandbox', 'production'], array_keys(NotificationQueue::MODES));
        foreach (\Source\Services\Erp\NotificationOutbox::EVENTS as $event) {
            self::assertArrayHasKey($event, NotificationQueue::EVENTS, 'todo evento da outbox precisa de rótulo na tela');
        }
    }

    public function testRoutesPermissionsAndCsrf(): void
    {
        $routes = $this->file('container/erp/connect/v1/routes.php');
        self::assertStringContainsString('"/configuracoes/fila-de-avisos", "NotificationQueue:index"', $routes);
        self::assertStringContainsString('"/configuracoes/fila-de-avisos/{id}/reenviar", "NotificationQueue:retry"', $routes);
        self::assertSame('erp.notifications.view', Panel::pagePermissions()['CN-005']);
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        self::assertSame('/configuracoes/fila-de-avisos', $catalog['pages']['CN-005']['route']);
        $controller = $this->file('source/Controllers/Erp/Connect/NotificationQueue.php');
        self::assertStringContainsString('csrf_verify($data)', $controller);
        self::assertStringContainsString("Access::can('erp.notifications.manage', \$user)", $controller);
        self::assertStringContainsString('->retry($user, $id)', $controller);
        $view = $this->file('container/erp/connect/v1/pages/notifications/index.php');
        self::assertStringContainsString("in_array(\$n['status'], ['failed', 'cancelled'], true)", $view);
        self::assertStringContainsString('csrf_input()', $view);
    }
}
