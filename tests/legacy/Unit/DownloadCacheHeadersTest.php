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
    /** Controladores antigos sem rota (aguardam a decisão de apagar); não respondem a nenhum endereço. */
    private const DEAD = ['source/Controllers/Erp/V1/'];

    public function testEveryDownloadIsPrivate(): void
    {
        $root = dirname(__DIR__, 3);
        $missing = [];
        $checked = 0;
        foreach (['source', 'container'] as $base) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $base, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($file->getExtension() !== 'php' || array_filter(self::DEAD, static fn (string $d): bool => str_starts_with($relative, $d)) !== []) {
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
}
