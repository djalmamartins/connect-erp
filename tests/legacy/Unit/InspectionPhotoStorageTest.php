<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;
use Source\Services\Inspections\InspectionError;
use Source\Services\Inspections\PhotoStorage;

/** Fotos de evidência: só imagem real, regravada sem metadados, fora da pasta pública. */
final class InspectionPhotoStorageTest extends TestCase
{
    private string $root;
    private array $temporary = [];

    protected function setUp(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            self::markTestSkipped('Extensão GD indisponível.');
        }
        $this->root = sys_get_temp_dir() . '/insp-photos-' . bin2hex(random_bytes(4)) . '/inspections';
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $file) {
            @unlink($file);
        }
        if (is_dir(dirname($this->root))) {
            exec('rm -rf ' . escapeshellarg(dirname($this->root)));
        }
    }

    public function testStoresReencodedResizedJpegWithoutExif(): void
    {
        $source = $this->jpeg(2400, 1200, withExif: true);
        self::assertStringContainsString('Exif', (string)file_get_contents($source), 'Pré-condição: a foto de origem tem EXIF.');

        $stored = (new PhotoStorage($this->root))->store(['tmp_name' => $source, 'error' => UPLOAD_ERR_OK], 7, 42);

        self::assertMatchesRegularExpression('~^7/42/[a-f0-9]{32}\.jpg$~', $stored['path']);
        self::assertSame([1600, 800], [$stored['width'], $stored['height']], 'Maior lado limitado a 1600 px.');
        $file = $this->root . '/' . $stored['path'];
        self::assertFileExists($file);
        self::assertSame('image/jpeg', mime_content_type($file));
        self::assertStringNotContainsString('Exif', (string)file_get_contents($file), 'EXIF descartado (LGPD).');
        self::assertSame(hash_file('sha256', $file), $stored['sha256']);
        self::assertFileExists(dirname($this->root) . '/.htaccess', 'Pasta privada recebe bloqueio do Apache.');
        self::assertSame($file, (new PhotoStorage($this->root))->absolute($stored['path']));
    }

    public function testPngIsAcceptedAndConvertedToJpeg(): void
    {
        $image = imagecreatetruecolor(300, 200);
        $file = $this->temp('.png');
        imagepng($image, $file);
        $stored = (new PhotoStorage($this->root))->store(['tmp_name' => $file, 'error' => UPLOAD_ERR_OK], 1, 1);
        self::assertSame('image/jpeg', $stored['mime']);
        self::assertSame([300, 200], [$stored['width'], $stored['height']]);
    }

    public function testRejectsNonImagesAndDisguisedFiles(): void
    {
        $php = $this->temp('.jpg');
        file_put_contents($php, "<?php echo 'x';");
        $polyglot = $this->temp('.jpg');
        file_put_contents($polyglot, "GIF89a<?php system(\$_GET['c']); ?>");
        $pdf = $this->temp('.jpg');
        file_put_contents($pdf, "%PDF-1.4\n%âãÏÓ\n1 0 obj\n<<>>\nendobj\n");

        foreach ([$php, $polyglot, $pdf] as $file) {
            try {
                (new PhotoStorage($this->root))->store(['tmp_name' => $file, 'error' => UPLOAD_ERR_OK], 1, 1);
                self::fail('Arquivo que não é foto foi aceito: ' . basename($file));
            } catch (InspectionError $error) {
                self::assertSame(422, $error->status);
                self::assertArrayHasKey('photo', $error->errors);
            }
        }
        self::assertDirectoryDoesNotExist($this->root . '/1/1');
    }

    public function testRejectsUploadErrorsAndMissingFile(): void
    {
        $storage = new PhotoStorage($this->root);
        foreach ([['error' => UPLOAD_ERR_NO_FILE], ['error' => UPLOAD_ERR_INI_SIZE, 'tmp_name' => ''], ['error' => UPLOAD_ERR_OK, 'tmp_name' => '/nao/existe.jpg']] as $upload) {
            try {
                $storage->store($upload, 1, 1);
                self::fail('Upload inválido aceito.');
            } catch (InspectionError $error) {
                self::assertSame(422, $error->status);
            }
        }
    }

    public function testAbsoluteRefusesPathTraversal(): void
    {
        $storage = new PhotoStorage($this->root);
        foreach (['../../etc/passwd', '1/1/../../x.jpg', '/etc/passwd', '1/1/abc.jpg', ''] as $relative) {
            self::assertNull($storage->absolute($relative));
        }
    }

    private function temp(string $suffix): string
    {
        $file = sys_get_temp_dir() . '/insp-src-' . bin2hex(random_bytes(6)) . $suffix;
        $this->temporary[] = $file;
        return $file;
    }

    /** JPEG com um segmento APP1/Exif mínimo inserido logo após o SOI. */
    private function jpeg(int $width, int $height, bool $withExif = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, (int)($width / 2), $height, (int)imagecolorallocate($image, 200, 30, 30));
        ob_start();
        imagejpeg($image, null, 90);
        $jpeg = (string)ob_get_clean();
        if ($withExif) {
            $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x00\x00\x00\x00\x00";
            $payload = "Exif\x00\x00" . $tiff;
            $jpeg = substr($jpeg, 0, 2) . "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . substr($jpeg, 2);
        }
        $file = $this->temp('.jpg');
        file_put_contents($file, $jpeg);
        return $file;
    }
}
