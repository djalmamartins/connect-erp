<?php
declare(strict_types=1);

// Smoke sem MariaDB (A5): usa o autoload real do Composer no modo de testes sem banco
// (ADR 0006), em vez do antigo source/Connect/Support/bootstrap.php.
putenv('MOVESOS_ENV=testing');
putenv('MOVESOS_TEST_DB=' . (getenv('MOVESOS_TEST_DB') ?: 'movesos_test'));
putenv('MOVESOS_TEST_DB_DISABLED=1');
$_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';

require dirname(__DIR__) . '/vendor/autoload.php';

use Connect\Http\JsonResponse;

foreach ([JsonResponse::class, \Source\Core\Router::class, \Source\Support\Access::class] as $class) {
    if (!class_exists($class)) { fwrite(STDERR, "Autoload failed: {$class}.\n"); exit(1); }
}
$spec = dirname(__DIR__) . '/docs/api/openapi.yaml';
if (!is_file($spec) || !str_contains((string) file_get_contents($spec), '/health:')) {
    fwrite(STDERR, "OpenAPI health contract missing.\n"); exit(1);
}
echo "Smoke tests passed.\n";
