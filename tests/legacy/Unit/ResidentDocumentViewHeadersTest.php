<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Api\V1\ResidentApp;

/**
 * Documentos do app do morador (22/11/2026): no app web, PDF conferido abre no navegador (inline); o resto e a API
 * continuam como anexo com CSP sandbox (ADR 0393).
 */
final class ResidentDocumentViewHeadersTest extends TestCase
{
    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function file(string $content, string $mime): array
    {
        $path = (string)tempnam(sys_get_temp_dir(), 'doc');
        file_put_contents($path, $content);
        $this->files[] = $path;
        return ['path' => $path, 'mime' => $mime, 'name' => 'Regulamento interno.pdf', 'size' => strlen($content)];
    }

    public function testRealPdfOpensInlineWhenViewing(): void
    {
        $headers = ResidentApp::fileHeaders($this->file("%PDF-1.4\n...", 'application/pdf'), true);
        self::assertContains('Content-Type: application/pdf', $headers);
        self::assertContains('Content-Disposition: inline; filename="Regulamento-interno.pdf"', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
        self::assertContains("Content-Security-Policy: frame-ancestors 'self'", $headers);
    }

    public function testFakePdfStaysSandboxedAttachment(): void
    {
        $headers = ResidentApp::fileHeaders($this->file('<html><script>alert(1)</script>', 'application/pdf'), true);
        self::assertContains('Content-Disposition: attachment; filename="Regulamento-interno.pdf"', $headers);
        self::assertContains("Content-Security-Policy: default-src 'none'; sandbox", $headers);
    }

    public function testOtherTypesAndApiStayAttachment(): void
    {
        $docx = ResidentApp::fileHeaders($this->file('PK...', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), true);
        self::assertContains("Content-Security-Policy: default-src 'none'; sandbox", $docx);
        $api = ResidentApp::fileHeaders($this->file("%PDF-1.4\n...", 'application/pdf'));
        self::assertContains('Content-Disposition: attachment; filename="Regulamento-interno.pdf"', $api);
        self::assertContains("Content-Security-Policy: default-src 'none'; sandbox", $api);
    }
}
