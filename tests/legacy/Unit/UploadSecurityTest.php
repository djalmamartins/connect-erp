<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\Upload;

final class UploadSecurityTest extends TestCase
{
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        parent::tearDown();
    }

    public function testRejectsSpoofedImageMimeType(): void
    {
        $file = $this->temporaryFile('<?php echo "não é imagem";');
        $result = (new Upload())->image([
            'name' => 'foto.png', 'type' => 'image/png', 'tmp_name' => $file,
            'error' => UPLOAD_ERR_OK, 'size' => filesize($file)
        ], 'imagem-falsa');

        self::assertNull($result);
    }

    public function testRejectsMissingAndEmptyTemporaryFile(): void
    {
        self::assertNull((new Upload())->image([
            'name' => 'foto.png', 'type' => 'image/png', 'tmp_name' => '/arquivo/inexistente',
            'error' => UPLOAD_ERR_OK, 'size' => 100
        ], 'inexistente'));
    }

    public function testRemoveCannotDeleteOutsideStorage(): void
    {
        $file = $this->temporaryFile('preservar');
        (new Upload())->remove($file);
        self::assertFileExists($file);
    }

    private function temporaryFile(string $contents): string
    {
        $file = tempnam(sys_get_temp_dir(), 'movesos-upload-');
        file_put_contents($file, $contents);
        $this->temporaryFiles[] = $file;
        return $file;
    }
}
