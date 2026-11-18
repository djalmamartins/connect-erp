<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Todo download (Content-Disposition) sai com Cache-Control "private" (ADR 0391): PDF e planilha com dado pessoal não
 * ficam guardados em cache compartilhado (proxy, CDN) nem são servidos a outra pessoa. Teste estático: confere cada
 * header('Content-Disposition...') do código e pede um Cache-Control com private até 8 linhas antes ou depois.
 */
final class DownloadCacheHeadersTest extends TestCase
{
    public function testEveryDownloadIsPrivate(): void
    {
        $root = dirname(__DIR__, 3);
        $missing = [];
        $checked = 0;
        foreach (['source', 'container'] as $base) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $base, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $lines = file($file->getPathname()) ?: [];
                foreach ($lines as $index => $line) {
                    if (!preg_match('/header\(\s*[\'"]Content-Disposition/i', $line)) {
                        continue;
                    }
                    $checked++;
                    $window = implode('', array_slice($lines, max(0, $index - 8), 17));
                    if (!preg_match('/Cache-Control:[^\'"]*private/i', $window)) {
                        $missing[] = $relative . ':' . ($index + 1);
                    }
                }
            }
        }
        self::assertGreaterThanOrEqual(20, $checked, 'o teste deixou de achar os downloads');
        self::assertSame([], $missing, 'Download sem Cache-Control private');
    }

    /**
     * ADR 0393: anexo enviado por alguém (tipo vindo do envio) sai com CSP sandbox, como o Disco Virtual e os anexos dos
     * lançamentos: mesmo se o navegador abrir em vez de baixar, nada roda como página do sistema.
     */
    public function testUploadedFilesAreServedSandboxed(): void
    {
        $root = dirname(__DIR__, 3);
        foreach (['source/Controllers/Api/V1/TicketAttachments.php', 'source/Controllers/Api/V1/ResidentApp.php', 'source/Controllers/Api/V1/VirtualDisk.php',
            'source/Controllers/Api/V1/EntryAttachments.php', 'source/Controllers/Erp/Connect/EntryAttachmentScreens.php',
            'source/Controllers/Web/Connect/AccountabilityLinkPublic.php'] as $file) {
            $code = (string)file_get_contents($root . '/' . $file);
            self::assertMatchesRegularExpression('/Content-Security-Policy:[^\n]*sandbox/', $code, $file);
        }
    }
}
