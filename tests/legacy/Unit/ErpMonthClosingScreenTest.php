<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Tela do fechamento mensal (AP-030, ADR 0031/0032): lê $closing, $closingCompetence e
 * $canManageClosing de Panel::closingData e envia Fechar/Reabrir por POST para MonthClosings.
 */
final class ErpMonthClosingScreenTest extends TestCase
{
    private function theme(string $file): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/container/erp/connect/v1/' . $file);
    }

    public function testViewUsesTheControllerContract(): void
    {
        $view = $this->theme('pages/modules/closing.php');
        foreach (['$closing', '$closingCompetence', '$canManageClosing', "erp_url('/financeiro/fechamento-mensal')", "\$route . '/fechar'", "\$route . '/reabrir'", 'name="competence"', 'name="reason" minlength="5" maxlength="255" required', 'csrf_input()', 'name="competencia"'] as $needle) {
            self::assertStringContainsString($needle, $view);
        }
        self::assertSame(2, substr_count($view, 'method="post"'), 'só Fechar e Reabrir gravam');
    }

    public function testRoutesPointToMonthClosings(): void
    {
        $routes = $this->theme('routes.php');
        self::assertStringContainsString('$route->post("/financeiro/fechamento-mensal/fechar", "MonthClosings:close");', $routes);
        self::assertStringContainsString('$route->post("/financeiro/fechamento-mensal/reabrir", "MonthClosings:reopen");', $routes);
    }

    public function testConfirmationHandlerIsInTheSourceAndTheBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            self::assertStringContainsString('form[data-erp-confirm]', $this->theme($file), $file);
        }
        foreach (['assets/css/erp.css', 'assets/erp.min.css'] as $file) {
            self::assertStringContainsString('.erp-closing-status', $this->theme($file), $file);
        }
    }
}
