<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas de inadimplência e acordos (AP-033/034, ADR 0039 e 0041): dados de Panel::agreementData,
 * ações pela API /api/v1/agreements com o CSRF no cabeçalho.
 */
final class ErpAgreementsScreensTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOwnTemplatesOnlyWithRealData(): void
    {
        self::assertNull(Panel::ownTemplate('AP-033', ['delinquency' => null]));
        self::assertSame('pages/finance/agreements', Panel::ownTemplate('AP-033', ['delinquency' => ['units' => []]]));
        self::assertNull(Panel::ownTemplate('AP-034', ['agreementParcels' => null]));
        self::assertSame('pages/finance/agreement-parcels', Panel::ownTemplate('AP-034', ['agreementParcels' => []]));
    }

    public function testParcelsAreFlattenedWithTheirStateOnTheDate(): void
    {
        $agreement = ['id' => 4, 'installments' => 2, 'unit_label' => 'Apto 101', 'condominium_name' => 'Res', 'parcels' => [
            ['number' => 1, 'entry_id' => 10, 'due_on' => '2026-11-09', 'amount_cents' => 100, 'paid_on' => null],
            ['number' => 2, 'entry_id' => 11, 'due_on' => '2026-11-08', 'amount_cents' => 100, 'paid_on' => null],
        ]];
        $rows = Panel::flattenParcels([$agreement, ['id' => 5, 'installments' => 1, 'parcels' => [['number' => 1, 'entry_id' => 12, 'due_on' => '2026-10-01', 'amount_cents' => 50, 'paid_on' => '2026-10-01']]]], '2026-11-09');
        self::assertSame(['late', 'open', 'paid'], array_column($rows, 'state'), 'vence hoje ainda não está vencida');
        self::assertSame([4, 4, 5], array_column($rows, 'agreement_id'));
        self::assertSame(2, $rows[0]['installments']);
    }

    public function testViewsOnlyOfferWritesToManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/finance/agreements.php');
        self::assertStringContainsString("\$canManageAgreements && \$unit['unit_id'] !== null", $view);
        self::assertStringContainsString("\$canManageAgreements && \$active && (int)\$agreement['paid_installments'] === 0", $view);
        self::assertStringContainsString('name="reason" minlength="5" maxlength="255" required', $view);
        self::assertStringContainsString('csrf_input()', $view);
        self::assertStringContainsString("!Access::can('erp.agreements.view', \$user)", $this->file('source/Controllers/Erp/Connect/Panel.php'));
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-agreements]', "'/cancel'", 'entry_ids', 'chart_account_id', 'first_due_on', 'X-CSRF-Token'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
