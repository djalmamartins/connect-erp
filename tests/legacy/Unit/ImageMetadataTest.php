<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Support\ImageMetadata;
use Source\Support\Upload;

/** ADR 0389: imagens públicas sem EXIF (GPS, aparelho) e sem textos do PNG, com a orientação aplicada. */
final class ImageMetadataTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/image-metadata-' . bin2hex(random_bytes(4));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** JPEG 40x20 com EXIF: Make (texto marcador) e Orientation 6 (foto em pé, gravada deitada). */
    private function jpegWithExif(string $marker): string
    {
        $image = imagecreatetruecolor(40, 20);
        imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image);
        $jpeg = (string)ob_get_clean();
        $make = $marker . "\0";
        $tiff = "II*\0" . pack('V', 8) . pack('v', 2)
            . pack('vvVV', 0x010F, 2, strlen($make), 38)
            . pack('vvV', 0x0112, 3, 1) . pack('v', 6) . "\0\0"
            . pack('V', 0) . $make;
        $payload = "Exif\0\0" . $tiff;
        $file = $this->dir . '/foto.jpg';
        file_put_contents($file, substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2));
        return $file;
    }

    public function testJpegLosesExifAndKeepsOrientation(): void
    {
        $file = $this->jpegWithExif('GPS-SEGREDO-123');
        self::assertSame(6, (int)(exif_read_data($file)['Orientation'] ?? 0), 'fixture sem EXIF');
        self::assertStringContainsString('GPS-SEGREDO-123', (string)file_get_contents($file));

        self::assertTrue(ImageMetadata::strip($file, 'image/jpeg'));

        $bytes = (string)file_get_contents($file);
        self::assertStringNotContainsString('GPS-SEGREDO-123', $bytes);
        self::assertStringNotContainsString("Exif\0\0", $bytes);
        $info = getimagesize($file);
        self::assertSame([20, 40, 'image/jpeg'], [$info[0], $info[1], $info['mime']], 'a foto deve ficar em pé');
    }

    public function testPngLosesTextChunksAndKeepsTransparency(): void
    {
        $image = imagecreatetruecolor(10, 10);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        ob_start();
        imagepng($image);
        $png = (string)ob_get_clean();
        $text = 'Comment' . "\0" . 'LOCAL-SEGREDO';
        $chunk = pack('N', strlen($text)) . 'tEXt' . $text . pack('N', crc32('tEXt' . $text));
        $file = $this->dir . '/logo.png';
        file_put_contents($file, substr($png, 0, -12) . $chunk . substr($png, -12));

        self::assertTrue(ImageMetadata::strip($file, 'image/png'));

        self::assertStringNotContainsString('LOCAL-SEGREDO', (string)file_get_contents($file));
        $back = imagecreatefrompng($file);
        self::assertSame(127, (imagecolorat($back, 5, 5) >> 24) & 0x7F, 'a transparência deve continuar');
    }

    public function testGifStaysAsItCameAndBrokenImagesAreRefused(): void
    {
        $gif = $this->dir . '/anim.gif';
        file_put_contents($gif, $original = base64_decode('R0lGODlhAQABAIAAAP///wAAACH5BAEAAAAALAAAAAABAAEAAAICRAEAOw=='));
        self::assertTrue(ImageMetadata::strip($gif, 'image/gif'));
        self::assertSame($original, file_get_contents($gif));

        $broken = $this->dir . '/quebrada.jpg';
        file_put_contents($broken, "\xFF\xD8\xFF\xE0 isto não é uma foto");
        self::assertFalse(ImageMetadata::strip($broken, 'image/jpeg'));
        self::assertFalse(ImageMetadata::strip($this->dir . '/sumiu.png', 'image/png'));
        self::assertFalse(ImageMetadata::strip($gif, 'image/svg+xml'));
        self::assertSame([0, 180, -90, 90, 0], array_map([ImageMetadata::class, 'angle'], [1, 3, 6, 8, 99]));
    }

    public function testUploadImageStoresTheCleanCopy(): void
    {
        $file = $this->jpegWithExif('GPS-SEGREDO-456');
        $cwd = (string)getcwd();
        chdir($this->dir);
        try {
            $stored = (new Upload())->image(['name' => 'foto.jpg', 'type' => 'image/jpeg', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)], 'capa-teste', 600);
        } finally {
            chdir($cwd);
        }
        self::assertNotNull($stored);
        $path = $this->dir . '/' . CONF_UPLOAD_DIR . '/' . $stored;
        self::assertFileExists($path);
        self::assertStringNotContainsString('GPS-SEGREDO-456', (string)file_get_contents($path));
        self::assertSame(20, getimagesize($path)[0]);
    }
}
