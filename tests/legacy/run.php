<?php

declare(strict_types=1);

putenv('MOVESOS_ENV=testing');
putenv('MOVESOS_TEST_DB=' . (getenv('MOVESOS_TEST_DB') ?: 'movesos_test'));

require __DIR__ . '/bootstrap.php';
require dirname(__DIR__, 2) . '/vendor/phpunit/phpunit/phpunit';
