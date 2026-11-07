<?php
declare(strict_types=1);
require dirname(__DIR__) . '/source/Connect/Support/bootstrap.php';
use Connect\Http\JsonResponse;
if (!class_exists(JsonResponse::class)) { fwrite(STDERR, "Autoload failed.\n"); exit(1); }
$spec = dirname(__DIR__) . '/docs/api/openapi.yaml';
if (!is_file($spec) || !str_contains((string) file_get_contents($spec), '/health:')) {
    fwrite(STDERR, "OpenAPI health contract missing.\n"); exit(1);
}
echo "Smoke tests passed.\n";
