<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas de encargos (ADR 0043): regra de cobrança e IPCA (AP-165), encargos calculados na
 * inadimplência, sugestão no "Fazer acordo" e quebra explícita do acordo (AP-033).
 */
final class ErpBillingScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOwnTemplateOnlyWithRealRule(): void
    {
        self::assertNull(Panel::ownTemplate('AP-165', ['billingRule' => null]));
        self::assertSame('pages/finance/billing-rules', Panel::ownTemplate('AP-165', ['billingRule' => ['source' => 'builtin']]));
    }

    public function testWritesOnlyForManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/finance/billing-rules.php');
        self::assertStringContainsString('$disabled = $canManageBilling ? \'\' : \' disabled\';', $view);
        self::assertStringContainsString('<?php if ($canManageBilling): ?><div class="erp-form__actions">', $view);
        self::assertStringContainsString('csrf_input()', $view);
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("Access::can('erp.billing_rules.view', \$user)", $panel);
        self::assertStringContainsString("Access::can('erp.billing_rules.manage', \$user)", $panel);
        $agreements = $this->file('container/erp/connect/v1/pages/finance/agreements.php');
        self::assertStringContainsString('data-agreement-breach=', $agreements);
        self::assertStringContainsString('$breachDue', $agreements);
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-billing]', "'/import'", "'/suggestion?unit_id='", "'/breach'", 'monthly_interest_bp', 'breach_open_installments', 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
