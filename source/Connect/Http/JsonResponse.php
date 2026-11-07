<?php
declare(strict_types=1);
namespace Connect\Http;
final class JsonResponse
{
    /** @param array<string, mixed> $payload */
    public static function send(int $status, array $payload): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        echo json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
