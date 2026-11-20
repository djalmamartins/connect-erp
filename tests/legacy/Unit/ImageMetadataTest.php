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
        self::assertSame(6, ImageMetadata::orientation($file), 'fixture sem EXIF');
        self::assertSame(6, ImageMetadata::jpegOrientation((string)file_get_contents($file)), 'leitura sem a extensão exif');
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
        self::assertSame(1, ImageMetadata::jpegOrientation('não é jpeg'));
        self::assertSame(1, ImageMetadata::jpegOrientation("\xFF\xD8\xFF\xE1\x00\x08Exif\0\0"), 'EXIF cortado');
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

    public function testDetectsMetadataAndCleansOnlyOldImagesThatHaveIt(): void
    {
        $exif = $this->jpegWithExif('GPS-ANTIGA-789');
        mkdir($this->dir . '/images/2025/03', 0700, true);
        rename($exif, $old = $this->dir . '/images/2025/03/antiga.jpg');
        $plain = imagecreatetruecolor(8, 8);
        imagejpeg($plain, $clean = $this->dir . '/images/2025/03/limpa.jpg');
        imagepng($plain, $this->dir . '/images/2025/03/fake.jpg'); // PNG com nome de JPEG, sem metadados
        $pngText = 'Comment' . "\0" . 'X';
        ob_start();
        imagepng($plain);
        $png = (string)ob_get_clean();
        file_put_contents($wrong = $this->dir . '/images/2025/03/troca.webp', substr($png, 0, -12) . pack('N', strlen($pngText)) . 'tEXt' . $pngText . pack('N', crc32('tEXt' . $pngText)) . substr($png, -12));
        file_put_contents($this->dir . '/images/2025/03/anim.gif', 'GIF89a');
        $cleanBytes = (string)file_get_contents($clean);

        self::assertTrue(ImageMetadata::hasMetadata($old));
        self::assertFalse(ImageMetadata::hasMetadata($clean));
        self::assertTrue(ImageMetadata::hasMetadata($wrong));

        $count = ImageMetadata::cleanFolder($this->dir . '/images', false);
        self::assertSame(['checked' => 4, 'with_metadata' => 2, 'cleaned' => 0, 'failed' => []], $count);
        self::assertStringContainsString('GPS-ANTIGA-789', (string)file_get_contents($old), 'a contagem não muda nada');

        $done = ImageMetadata::cleanFolder($this->dir . '/images', true);
        self::assertSame(['checked' => 4, 'with_metadata' => 2, 'cleaned' => 1, 'failed' => ['2025/03/troca.webp']], $done);
        self::assertStringNotContainsString('GPS-ANTIGA-789', (string)file_get_contents($old));
        self::assertFalse(ImageMetadata::hasMetadata($old));
        self::assertSame($cleanBytes, file_get_contents($clean), 'imagem sem metadados não é regravada');
        self::assertSame(['checked' => 0, 'with_metadata' => 0, 'cleaned' => 0, 'failed' => []], ImageMetadata::cleanFolder($this->dir . '/nao-existe', true));
    }
}
