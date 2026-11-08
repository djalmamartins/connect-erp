<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

/**
 * Fotos de evidência da vistoria, fora do acesso público (storage/private/inspections).
 *
 * O arquivo enviado nunca é gravado como veio: o tipo é conferido pelo conteúdo (finfo +
 * getimagesize), a imagem é decodificada pelo GD, girada pela orientação EXIF, reduzida
 * a no máximo 1600 px e gravada de novo como JPEG. Isso descarta metadados (EXIF com
 * modelo do aparelho e GPS, LGPD) e qualquer conteúdo que não seja imagem.
 */
final class PhotoStorage
{
    public const MAX_BYTES = 15728640; // 15 MB: fotos de celular sem compressão
    public const MAX_SIDE = 1600;
    public const QUALITY = 82;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_PIXELS = 50000000; // evita estouro de memória com imagens gigantes

    private string $root;

    public function __construct(?string $root = null)
    {
        $this->root = rtrim($root ?? self::defaultRoot(), '/');
    }

    public static function defaultRoot(): string
    {
        return dirname(__DIR__, 3) . '/storage/private/inspections';
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * @param array{tmp_name?: string, error?: int, size?: int} $upload item de $_FILES
     * @return array{path: string, mime: string, size_bytes: int, sha256: string, width: int, height: int}
     * @throws InspectionError
     */
    public function store(array $upload, int $condominiumId, int $inspectionId): array
    {
        $temporary = (string)($upload['tmp_name'] ?? '');
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw InspectionError::invalid(['photo' => 'A foto passa do tamanho máximo de 15 MB.']);
        }
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_file($temporary)) {
            throw InspectionError::invalid(['photo' => 'Selecione uma foto.']);
        }
        $size = (int)filesize($temporary);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw InspectionError::invalid(['photo' => 'A foto passa do tamanho máximo de 15 MB.']);
        }
        $mime = strtolower((string)(new \finfo(FILEINFO_MIME_TYPE))->file($temporary));
        $info = @getimagesize($temporary);
        if (!in_array($mime, self::ALLOWED, true) || !$info || strtolower((string)$info['mime']) !== $mime) {
            throw InspectionError::invalid(['photo' => 'Envie uma foto em JPG, PNG ou WebP.']);
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            throw InspectionError::invalid(['photo' => 'A foto tem resolução grande demais.']);
        }

        $image = @imagecreatefromstring((string)file_get_contents($temporary));
        if ($image === false) {
            throw InspectionError::invalid(['photo' => 'Não foi possível ler a foto.']);
        }
        $image = $this->orient($image, $mime, $temporary);
        $image = $this->resize($image);

        $relative = sprintf('%d/%d/%s.jpg', $condominiumId, $inspectionId, bin2hex(random_bytes(16)));
        $target = $this->root . '/' . $relative;
        $this->prepareDirectory(dirname($target));
        $width = imagesx($image);
        $height = imagesy($image);
        $written = imagejpeg($image, $target, self::QUALITY);
        unset($image); // imagedestroy() é no-op desde o PHP 8.0 e deprecated no 8.5
        if (!$written || !is_file($target)) {
            throw new InspectionError('storage_failed', 'Não foi possível guardar a foto.', 500);
        }
        @chmod($target, 0640);
        return [
            'path' => $relative,
            'mime' => 'image/jpeg',
            'size_bytes' => (int)filesize($target),
            'sha256' => (string)hash_file('sha256', $target),
            'width' => $width,
            'height' => $height,
        ];
    }

    /** Caminho absoluto de uma foto já gravada, sem permitir sair da pasta privada. */
    public function absolute(string $relative): ?string
    {
        if (!preg_match('~^\d+/\d+/[a-f0-9]{32}\.jpg$~', $relative)) {
            return null;
        }
        $path = $this->root . '/' . $relative;
        return is_file($path) ? $path : null;
    }

    public function delete(string $relative): void
    {
        $path = $this->absolute($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    private function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new InspectionError('storage_failed', 'Não foi possível preparar a pasta das fotos.', 500);
        }
        // Defesa extra caso a pasta storage fique dentro da raiz pública do Apache.
        $base = dirname($this->root);
        if (is_dir($base) && !is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
    }

    private function orient(\GdImage $image, string $mime, string $file): \GdImage
    {
        if ($mime !== 'image/jpeg' || !function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $angle = match ((int)($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            return $image;
        }
        unset($image); // imagedestroy() é no-op desde o PHP 8.0 e deprecated no 8.5
        return $rotated;
    }

    private function resize(\GdImage $image): \GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1.0, self::MAX_SIDE / max($width, $height));
        $newWidth = max(1, (int)round($width * $scale));
        $newHeight = max(1, (int)round($height * $scale));
        $canvas = imagecreatetruecolor($newWidth, $newHeight);
        // Fundo branco para PNG/WebP com transparência, já que a saída é JPEG.
        imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
        unset($image); // imagedestroy() é no-op desde o PHP 8.0 e deprecated no 8.5
        return $canvas;
    }
}
