<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Tira os metadados de uma imagem enviada antes de gravá-la em pasta pública (ADR 0389).
 *
 * Foto de celular leva no EXIF a localização (GPS), a data e o modelo do aparelho. As imagens do Studio (blog, páginas,
 * equipe, depoimentos, Central de Ajuda) e a foto do condomínio ficam públicas, então a localização de quem tirou a foto
 * ia junto (LGPD). A imagem é decodificada pelo GD, girada pela orientação do EXIF (senão a foto em pé fica deitada) e
 * regravada no mesmo arquivo e no mesmo formato, o que descarta EXIF, XMP e os textos do PNG.
 *
 * GIF fica como veio: não leva EXIF e regravar perderia a animação.
 */
final class ImageMetadata
{
    public const MAX_PIXELS = 50000000; // mesmo teto das fotos de vistoria: evita estouro de memória
    public const JPEG_QUALITY = 90;
    public const WEBP_QUALITY = 90;

    /** Regrava $file sem metadados. Devolve false quando a imagem não pode ser lida (o envio deve ser recusado). */
    public static function strip(string $file, string $mime): bool
    {
        $mime = strtolower($mime);
        if ($mime === 'image/gif') {
            return true;
        }
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !is_file($file)) {
            return false;
        }
        $info = @getimagesize($file);
        if (!$info || (int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            return false;
        }
        $image = @imagecreatefromstring((string)file_get_contents($file));
        if ($image === false) {
            return false;
        }
        if ($mime === 'image/jpeg') {
            $image = self::orient($image, $file);
        } else {
            imagealphablending($image, false);
            imagesavealpha($image, true);
        }
        $written = match ($mime) {
            'image/jpeg' => imagejpeg($image, $file, self::JPEG_QUALITY),
            'image/png' => imagepng($image, $file, 6),
            'image/webp' => imagewebp($image, $file, self::WEBP_QUALITY),
        };
        clearstatcache(true, $file);
        return $written;
    }

    /** Ângulo para o imagerotate a partir da orientação do EXIF (regra pura). */
    public static function angle(int $orientation): int
    {
        return match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
    }

    private static function orient(\GdImage $image, string $file): \GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }
        $exif = @exif_read_data($file);
        $angle = self::angle((int)(is_array($exif) ? ($exif['Orientation'] ?? 1) : 1));
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        return $rotated === false ? $image : $rotated;
    }
}
