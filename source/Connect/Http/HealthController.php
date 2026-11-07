<?php
declare(strict_types=1);
namespace Connect\Http;
use Connect\Infrastructure\DatabaseConnection;
final class HealthController
{
    public function handle(): void
    {
        $database = 'unavailable';
        $status = 503;
        try {
            $database = (int) DatabaseConnection::create()->query('SELECT 1')->fetchColumn() === 1 ? 'ready' : 'unavailable';
            $status = $database === 'ready' ? 200 : 503;
        } catch (\Throwable $error) {
            error_log('Database health check failed: ' . $error->getMessage());
        }
        JsonResponse::send($status, ['data' => [
            'service' => 'connect-erp',
            'status' => $status === 200 ? 'ready' : 'degraded',
            'checks' => ['database' => $database],
        ]]);
    }
}
