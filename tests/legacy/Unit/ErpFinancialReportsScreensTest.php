<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas dos relatórios financeiros (ADR 0037): AP-079 balancete e AP-051 demonstrativo leem
 * $trialBalance / $statement, $reportPeriod e $reportError de Panel::reportData.
 */
final class ErpFinancialReportsScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testRealScreensOnlyWithDataOrPeriodError(): void
    {
        self::assertNull(Panel::ownTemplate('AP-079', ['trialBalance' => null, 'reportError' => null]), 'sem permissão ou banco: demonstração');
        self::assertSame('pages/reports/trial-balance', Panel::ownTemplate('AP-079', ['trialBalance' => ['rows' => []], 'reportError' => null]));
        self::assertSame('pages/reports/trial-balance', Panel::ownTemplate('AP-079', ['trialBalance' => null, 'reportError' => 'Período inválido']));
        self::assertSame('pages/reports/statement', Panel::ownTemplate('AP-051', ['statement' => ['accounts' => []], 'reportError' => null]));
        self::assertNull(Panel::ownTemplate('AP-080', ['trialBalance' => ['rows' => []]]));
    }

    public function testViewsUseThePeriodAndExposeTheDifference(): void
    {
        $trial = $this->file('container/erp/connect/v1/pages/reports/trial-balance.php');
        self::assertStringContainsString('type="month" id="tb-from" name="de"', $trial);
        self::assertStringContainsString('$reportError !== null', $trial);
        $statement = $this->file('container/erp/connect/v1/pages/reports/statement.php');
        self::assertStringContainsString('type="date" id="st-from" name="de"', $statement);
        self::assertStringContainsString("\$t('difference_cents')", $statement);
        self::assertStringContainsString("\$totals['reconciled']", $statement);
    }
}
