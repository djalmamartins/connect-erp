<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Tela do Disco Virtual (AP-122, ADR 0047 e 0049): lista por pasta (Panel::virtualDiskData), envio
 * multipart com o CSRF no cabeçalho, download pela API e exclusão.
 */
final class ErpVirtualDiskScreenTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testOwnTemplateOnlyWithRealData(): void
    {
        self::assertNull(Panel::ownTemplate('AP-122', ['diskFiles' => null]));
        self::assertSame('pages/documents/virtual-disk', Panel::ownTemplate('AP-122', ['diskFiles' => ['data' => []]]));
    }

    public function testReadableSizes(): void
    {
        self::assertSame('0 B', Panel::fileSize(0));
        self::assertSame('1023 B', Panel::fileSize(1023));
        self::assertSame('1,0 KB', Panel::fileSize(1024));
        self::assertSame('20,0 MB', Panel::fileSize(20971520));
    }

    public function testClientAcceptListMatchesTheServer(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/documents/virtual-disk.php');
        preg_match("/\\\$accept = '([^']+)'/", $view, $m);
        $client = explode(',', str_replace('.', '', $m[1] ?? ''));
        $server = array_merge(...array_values(\Source\Services\Erp\VirtualDisk::ALLOWED));
        sort($client);
        $server = array_values(array_unique($server));
        sort($server);
        self::assertSame($server, $client, 'o aviso do navegador usa as mesmas extensões do servidor');
    }

    public function testWritesOnlyForManagers(): void
    {
        $view = $this->file('container/erp/connect/v1/pages/documents/virtual-disk.php');
        self::assertMatchesRegularExpression('/<\?php if \(\$canManageFiles\): \?>\s*<form class="erp-card erp-form erp-disk-upload"/', $view);
        self::assertStringContainsString('<?php if ($canManageFiles): ?><button type="button" class="erp-btn erp-btn--sm" data-disk-delete=', $view);
        self::assertStringContainsString('csrf_input()', $view);
        $panel = $this->file('source/Controllers/Erp/Connect/Panel.php');
        self::assertStringContainsString("!Access::can('erp.documents.view', \$user)", $panel);
        self::assertStringContainsString("Access::can('erp.documents.manage', \$user)", $panel);
    }

    public function testJavascriptCallsEveryEndpointInSourceAndBundle(): void
    {
        foreach (['assets/js/erp.js', 'assets/erp.min.js'] as $file) {
            $js = $this->file('container/erp/connect/v1/' . $file);
            foreach (['[data-erp-virtual-disk]', 'FormData', "'X-CSRF-Token'", "'DELETE'", 'data-max-bytes'] as $needle) {
                self::assertStringContainsString($needle, $js, "$file: $needle");
            }
        }
    }
}
