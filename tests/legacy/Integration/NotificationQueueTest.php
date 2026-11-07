<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Models\Notification\NotificationMessage;

final class NotificationQueueTest extends TestCase
{
    public function testCreatesReusableScheduledNotificationMessage(): void
    {
        $message = new NotificationMessage();
        $message->title = 'Manutenção programada';
        $message->body = 'O sistema ficará indisponível por alguns minutos.';
        $message->audience = 'all';
        $message->severity = 'warning';
        $message->delivery_channels = 'both';
        $message->status = 'scheduled';
        $message->scheduled_at = date('Y-m-d H:i:s', time() + 3600);

        self::assertTrue($message->save());
        self::assertSame('scheduled', (new NotificationMessage())->findById((int)$message->id)?->status);
    }

    public function testMailQueueStoresRetryPolicyAndSchedule(): void
    {
        $scheduledAt = date('Y-m-d H:i:s', time() + 3600);
        $statement = $this->pdo->prepare('INSERT INTO mail_queue (subject,body,from_email,from_name,recipient_email,recipient_name,status,scheduled_at,max_attempts) VALUES (?,?,?,?,?,?,?,?,?)');
        $statement->execute(['Tutorial MovesOS','Conteúdo','movesos@test.local','MovesOS','user@test.local','Usuário','pending',$scheduledAt,5]);

        $queued = $this->pdo->query('SELECT * FROM mail_queue ORDER BY id DESC LIMIT 1')->fetch();
        self::assertSame('pending', $queued->status);
        self::assertSame(0, (int)$queued->attempts);
        self::assertSame(5, (int)$queued->max_attempts);
        self::assertSame($scheduledAt, $queued->scheduled_at);
    }
}
