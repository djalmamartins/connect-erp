<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Controllers\Api\V1\Inspections;

/** Foto da vistoria pela API, para o app Meu Dia (ADR 0674): só imagem, cache privado, rota registrada. */
final class InspectionApiPhotoHeadersTest extends TestCase
{
    private string $path = '';

    protected function setUp(): void
    {
        $this->path = (string)tempnam(sys_get_temp_dir(), 'photo');
        file_put_contents($this->path, str_repeat('x', 42));
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
    }

    public function testJpegIsServedAsImageWithPrivateCache(): void
    {
        $headers = Inspections::photoHeaders(['path' => $this->path, 'mime' => 'image/jpeg', 'sha256' => str_repeat('ab', 32)]);
        self::assertContains('Content-Type: image/jpeg', $headers);
        self::assertContains('Content-Length: 42', $headers);
        self::assertContains('Cache-Control: private, max-age=3600', $headers);
        self::assertContains('X-Content-Type-Options: nosniff', $headers);
        self::assertContains("Content-Security-Policy: default-src 'none'", $headers);
        self::assertContains('ETag: "' . str_repeat('ab', 32) . '"', $headers);
    }

    public function testUnexpectedMimeIsNeverRenderedAsPage(): void
    {
        $headers = Inspections::photoHeaders(['path' => $this->path, 'mime' => 'text/html', 'sha256' => "zz\r\nX-Evil: 1"]);
        self::assertContains('Content-Type: application/octet-stream', $headers);
        self::assertContains('ETag: ""', $headers);
    }

    public function testRouteIsBehindApiAuthentication(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        self::assertStringContainsString('$route->get("/inspections/{id}/photos/{photo}", "Inspections:photoFile", null, \\Source\\Support\\Middleware\\ApiAuthenticated::class);', $index);
        self::assertStringContainsString('$route->delete("/inspections/{id}/photos/{photo}", "Inspections:deletePhoto", null, \\Source\\Support\\Middleware\\ApiAuthenticated::class);', $index);
        self::assertStringContainsString('$route->post("/inspections/{id}/cancel", "Inspections:cancel", null, \\Source\\Support\\Middleware\\ApiAuthenticated::class);', $index);
    }
}
