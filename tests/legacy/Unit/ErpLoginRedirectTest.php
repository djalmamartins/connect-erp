<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Issue #11: os controllers do ERP não podem mandar para "/login" cru, que só existe no
 * host do ERP ou no grupo do Studio. Devem usar erp_url('/login'), que respeita a base /erp.
 */
final class ErpLoginRedirectTest extends TestCase
{
    public function testErpConnectControllersUseErpUrlForLogin(): void
    {
        $offenders = [];
        foreach (glob(__DIR__ . '/../../../source/Controllers/Erp/Connect/*.php') ?: [] as $file) {
            $code = (string)file_get_contents($file);
            if (preg_match('/redirect\(\s*[\'"]\/login[\'"]\s*\)/', $code)) {
                $offenders[] = basename($file);
            }
        }
        self::assertSame([], $offenders, 'Use redirect(erp_url(\'/login\')) em: ' . implode(', ', $offenders));
    }
}
