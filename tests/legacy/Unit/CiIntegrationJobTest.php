<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/** ADR 0369: o CI roda a integração num MariaDB de verdade, não só os testes de unidade sem banco. */
final class CiIntegrationJobTest extends TestCase
{
    public function testWorkflowRunsIntegrationAgainstMariaDb(): void
    {
        $workflow = (string)file_get_contents(dirname(__DIR__, 3) . '/.github/workflows/quality.yml');
        self::assertMatchesRegularExpression('~^  php-integration:$~m', $workflow);
        self::assertMatchesRegularExpression('~image: mariadb:\d~', $workflow);
        self::assertStringContainsString('run: composer test:integration', $workflow);
        self::assertStringContainsString('MOVESOS_TEST_DB_HOST: 127.0.0.1', $workflow);
        self::assertStringNotContainsString('vendor/bin/phpunit', $workflow, 'só pelos scripts do composer (incidente de 07/11)');
    }
}
