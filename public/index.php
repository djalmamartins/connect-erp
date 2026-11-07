<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
require is_file($autoload) ? $autoload : $root . '/source/Connect/Support/bootstrap.php';

use Connect\Http\HealthController;
use Connect\Http\JsonResponse;

$path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
try {
    if ($path === '/api/v1/health') {
        (new HealthController())->handle();
        return;
    }
    if ($path === '/') {
        JsonResponse::send(200, ['data' => ['name' => 'Connect ERP', 'status' => 'foundation-ready', 'api' => '/api/v1/health']]);
        return;
    }
    JsonResponse::send(404, ['error' => ['code' => 'not_found', 'message' => 'Resource not found.']]);
} catch (Throwable $error) {
    error_log($error->__toString());
    JsonResponse::send(500, ['error' => ['code' => 'internal_error', 'message' => 'Unexpected server error.']]);
}
