<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Erp\Connect\FinanceScreens;
use Source\Controllers\Erp\Connect\OperationScreens;
use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\UnitScreens;

/**
 * Carregadores de tela que saíram do Panel (melhoria de arquitetura de 20/11/2026): cada página aponta para
 * um método que existe, nenhuma página fica em duas classes, os atalhos do Panel continuam iguais e o
 * Panel não monta mais esses dados por conta própria.
 */
final class ErpPanelScreensTest extends TestCase
{
    private const CLASSES = [FinanceScreens::class, UnitScreens::class, OperationScreens::class];

    public function testEveryPageHasALoader(): void
    {
        foreach (self::CLASSES as $class) {
            foreach ($class::PAGES as $page => $method) {
                self::assertMatchesRegularExpression('/^(AP|CN)-\d{3}$/', $page);
                self::assertTrue(method_exists($class, $method), "$class::$method ($page)");
            }
        }
    }

    public function testNoPageBelongsToTwoClasses(): void
    {
        $pages = array_merge(...array_map(static fn (string $class): array => array_keys($class::PAGES), self::CLASSES));
        self::assertSame(count($pages), count(array_unique($pages)));
    }

    public function testUnknownPageReturnsNothing(): void
    {
        $user = (new \ReflectionClass(\Source\Models\User::class))->newInstanceWithoutConstructor();
        foreach (self::CLASSES as $class) {
            self::assertSame([], (new $class())->data($user, 'AP-999'));
        }
    }

    public function testPanelShortcutsStayTheSame(): void
    {
        self::assertSame(UnitScreens::UNIT_DOCUMENT_PAGES, Panel::UNIT_DOCUMENT_PAGES);
        self::assertSame(UnitScreens::RECEIPT_PAGE, Panel::RECEIPT_PAGE);
        self::assertSame(UnitScreens::METER_KINDS, Panel::METER_KINDS);
        self::assertSame(FinanceScreens::REPORT_PAGES, Panel::REPORT_PAGES);
        self::assertSame(OperationScreens::fileSize(1536), Panel::fileSize(1536));
        self::assertSame(['AP-032', 'AP-079', 'AP-051', 'AP-030'], array_values(array_intersect(['AP-032', 'AP-079', 'AP-051', 'AP-030'], array_keys(FinanceScreens::PAGES))));
    }

    public function testPanelOnlyDelegates(): void
    {
        $panel = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString('$data += $this->screenData($user, $id);', $panel);
        foreach (['closingData', 'invoiceData', 'bankingData', 'unitDocumentData', 'virtualDiskData', 'unitPanelData'] as $loader) {
            self::assertStringNotContainsString("function $loader(", $panel, "$loader saiu do Panel");
        }
        foreach (self::CLASSES as $class) {
            self::assertStringContainsString(substr(strrchr($class, '\\'), 1) . '::class', $panel);
        }
    }
}
