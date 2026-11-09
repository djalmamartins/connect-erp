<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Erp\IntegrationStatus;

/** Integrações (ADR 0091): situação dos índices e do e-mail, sem expor endereço. */
final class IntegrationStatusTest extends TestCase
{
    public function testIndexState(): void
    {
        self::assertSame('ok', IntegrationStatus::indexState('2026-10', '2026-11-09'));
        self::assertSame('ok', IntegrationStatus::indexState('2026-09', '2026-11-09'));
        self::assertSame('stale', IntegrationStatus::indexState('2026-08', '2026-11-09'));
        self::assertSame('missing', IntegrationStatus::indexState(null, '2026-11-09'));
        self::assertSame('missing', IntegrationStatus::indexState('nov/26', '2026-11-09'));
    }

    public function testMailNeverShowsTheAddressAndOnlyProductionReachesResidents(): void
    {
        $sandbox = IntegrationStatus::mail('development', 'production', 'caixa@teste.test');
        self::assertSame(['mode' => 'sandbox', 'sandbox_inbox_configured' => true, 'reaches_residents' => false], $sandbox, 'Fora de produção nunca chega ao morador.');
        self::assertStringNotContainsString('caixa@teste.test', json_encode($sandbox));
        self::assertSame('log', IntegrationStatus::mail('development', 'sandbox', null)['mode']);
        self::assertTrue(IntegrationStatus::mail('production', 'production', null)['reaches_residents']);
    }
}
