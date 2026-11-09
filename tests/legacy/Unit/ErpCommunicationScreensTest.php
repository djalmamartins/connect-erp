<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\CommunicationScreens;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas de comunicação (AP-111, AP-137, AP-138 e AP-139; ADR 0059, 0065 e 0075): tela própria só com
 * dados, situação do envio, cancelar só o que não saiu, data de referência e filtro dos endereços.
 */
final class ErpCommunicationScreensTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testEveryPageHasItsTemplateAndPermission(): void
    {
        $catalog = require dirname(__DIR__, 3) . '/' . self::THEME . 'components/layout/catalog.php';
        $permissions = Panel::pagePermissions();
        foreach (CommunicationScreens::PAGES as $page => $template) {
            self::assertArrayHasKey($page, $catalog['pages']);
            self::assertSame('erp.announcements.view', $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame($template, Panel::ownTemplate($page, ['commReady' => true]));
            self::assertFileExists(dirname(__DIR__, 3) . '/' . self::THEME . $template . '.php');
        }
        self::assertSame(['AP-137' => 'erp.agreements.view', 'AP-139' => 'erp.residents.view'], CommunicationScreens::EXTRA_PERMISSION);
    }

    public function testStatusAndCancellable(): void
    {
        $base = ['status' => 'active', 'scheduled' => false, 'delivery' => ['pending' => 0, 'processing' => 0, 'sent' => 3, 'failed' => 0]];
        self::assertSame(['Enviado', 'success'], CommunicationScreens::statusOf($base));
        self::assertSame(['Agendado', 'info'], CommunicationScreens::statusOf(['scheduled' => true] + $base));
        self::assertSame(['Enviando', 'warning'], CommunicationScreens::statusOf(['delivery' => ['processing' => 1]] + $base));
        self::assertSame(['Com falha', 'danger'], CommunicationScreens::statusOf(['delivery' => ['failed' => 2]] + $base));
        self::assertSame(['Cancelado', 'plain'], CommunicationScreens::statusOf(['status' => 'cancelled', 'scheduled' => true] + $base));
        self::assertFalse(CommunicationScreens::cancellable($base));
        self::assertTrue(CommunicationScreens::cancellable(['delivery' => ['pending' => 1]] + $base));
        self::assertFalse(CommunicationScreens::cancellable(['status' => 'cancelled', 'delivery' => ['pending' => 1]] + $base));
    }

    public function testReferenceDateAndAddressFilter(): void
    {
        self::assertSame('2026-11-09', CommunicationScreens::referenceDate(null, '2026-11-09'));
        self::assertSame('2026-10-31', CommunicationScreens::referenceDate('2026-10-31', '2026-11-09'));
        self::assertNull(CommunicationScreens::referenceDate('2026-11-10', '2026-11-09'));
        self::assertNull(CommunicationScreens::referenceDate('2026-02-30', '2026-11-09'));
        self::assertNull(CommunicationScreens::referenceDate(['x'], '2026-11-09'));
        $units = [['unit_id' => 1, 'notices' => 2, 'billing' => 0], ['unit_id' => 2, 'notices' => 0, 'billing' => 1]];
        self::assertSame($units, CommunicationScreens::filterAddresses($units, null));
        self::assertSame([2], array_column(CommunicationScreens::filterAddresses($units, 'comunicado'), 'unit_id'));
        self::assertSame([1], array_column(CommunicationScreens::filterAddresses($units, 'cobranca'), 'unit_id'));
        self::assertSame($units, CommunicationScreens::filterAddresses($units, 'outro'));
    }

    public function testSendAndCancelGoThroughTheApi(): void
    {
        $view = $this->file(self::THEME . 'pages/communication/announcements.php');
        self::assertStringContainsString('csrf_input()', $view);
        self::assertStringContainsString("url('/api/v1/announcements')", $view);
        self::assertStringContainsString("url('/api/v1/announcements/collection')", $view);
        self::assertStringContainsString("'/api/v1/announcements/' . (int)\$r['id'] . '/cancel'", $view);
        self::assertStringContainsString('nl2br($this->e(', $view, 'o texto do comunicado sai escapado');
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString("\$\$('[data-erp-communication]')", $js);
        self::assertStringContainsString('unit_ids: marked.length === ok.length ? null : marked', $js);
        $service = $this->file('source/Services/Erp/Announcements.php');
        self::assertStringContainsString('return $this->recentlyReminded($this->condominium($user, $condominiumId));', $service, 'avisadas: escopo conferido');
    }

    public function testDeliveryNoticeExplainsThatNothingLeavesOutsideProduction(): void
    {
        // Teste da main (Djalma, 09/11, item 10): "enviou?" — fora de produção, a tela diz que nada sai.
        self::assertNull(CommunicationScreens::deliveryNotice('production'));
        self::assertStringContainsString('nenhum e-mail sai', (string)CommunicationScreens::deliveryNotice('log'));
        self::assertStringContainsString('caixa de teste', (string)CommunicationScreens::deliveryNotice('sandbox'));
        self::assertStringContainsString('nenhum e-mail sai', (string)CommunicationScreens::deliveryNotice('qualquer'), 'Modo desconhecido conta como registro.');
        self::assertContains(CommunicationScreens::deliveryMode(), ['log', 'sandbox', 'production']);
    }
}
