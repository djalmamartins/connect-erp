<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\CollectionScreens;
use Source\Controllers\Erp\Connect\CommunicationScreens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Services\Erp\Collections;
use Source\Services\Erp\Registry\Definitions;

/**
 * Telas de cobrança e multas (AP-116, AP-117 e AP-039; ADR 0080 e 0076): tela própria só com dados,
 * selos, título do andamento, envio pela API e o caso que só acompanha.
 */
final class ErpCollectionScreensTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1/';

    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testEveryPageHasItsTemplateAndPermission(): void
    {
        $permissions = Panel::pagePermissions();
        foreach (CollectionScreens::PAGES as $page => $template) {
            self::assertSame('erp.collections.view', $permissions[$page]);
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame($template, Panel::ownTemplate($page, ['collReady' => true]));
            self::assertFileExists(dirname(__DIR__, 3) . '/' . self::THEME . $template . '.php');
        }
        self::assertSame(['AP-117', 'AP-039'], CollectionScreens::NEEDS_AGREEMENTS);
    }

    public function testBadgesAndEventTitles(): void
    {
        self::assertSame(['Em aberto', 'info'], CollectionScreens::fineBadge('open'));
        self::assertSame(['Paga', 'success'], CollectionScreens::fineBadge('paid'));
        self::assertSame(['x', 'plain'], CollectionScreens::fineBadge('x'));
        foreach (array_keys(Collections::CASE_STATUSES) as $status) {
            self::assertSame(Collections::CASE_STATUSES[$status], CollectionScreens::caseBadge($status)[0]);
        }
        self::assertSame('Caso aberto', CollectionScreens::eventTitle(['kind' => 'opened']));
        self::assertSame('Nota', CollectionScreens::eventTitle(['kind' => 'note']));
        self::assertSame('Ajuizado → Suspenso', CollectionScreens::eventTitle(['kind' => 'status', 'from_status' => 'filed', 'to_status' => 'suspended']));
    }

    public function testScreensWriteThroughTheApiAndOnlyTrack(): void
    {
        $desk = $this->file(self::THEME . 'pages/collections/desk.php');
        self::assertStringContainsString("url('/api/v1/collection-contacts')", $desk);
        self::assertStringContainsString('csrf_input()', $desk);
        $cases = $this->file(self::THEME . 'pages/collections/legal-cases.php');
        self::assertStringContainsString("url('/api/v1/legal-cases')", $cases);
        self::assertStringContainsString("'/api/v1/legal-cases/' . (int)\$k['id'] . '/events'", $cases);
        self::assertStringContainsString('nada é enviado a advogado ou tribunal', $cases);
        $js = $this->file(self::THEME . 'assets/js/erp.js');
        self::assertStringContainsString("\$\$('[data-erp-collections]')", $js);
        self::assertStringContainsString("if (origin[0] === 'occurrence') body.occurrence_id", $js, 'AP-027 envia a origem da multa');
        $options = $this->file('source/Services/Erp/CollectionScreenOptions.php');
        self::assertStringContainsString('$this->scope->canAccess($user, $condominiumId)', $options);
    }

    public function testAddressPageGetsEverythingFromTheController(): void
    {
        self::assertSame(Definitions::get('residents')['fields']['relationship']['options'], CommunicationScreens::RELATIONSHIPS);
        $view = $this->file(self::THEME . 'pages/communication/addresses.php');
        self::assertStringNotContainsString('Definitions::', $view);
        self::assertStringNotContainsString('CommunicationScreens::', $view);
    }
}
