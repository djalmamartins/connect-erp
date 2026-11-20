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

    /**
     * A imagem leva metadados? JPEG: segmento APP1 (EXIF ou XMP); PNG: blocos eXIf, tEXt, iTXt ou zTXt; WebP: EXIF ou XMP.
     * Serve para a limpeza das imagens antigas regravar só o que precisa (regravar JPEG de novo perde qualidade).
     */
    public static function hasMetadata(string $file): bool
    {
        $head = (string)@file_get_contents($file, false, null, 0, 262144);
        if (str_starts_with($head, "\xFF\xD8")) {
            $offset = 2;
            while ($offset + 4 <= strlen($head) && $head[$offset] === "\xFF") {
                $marker = ord($head[$offset + 1]);
                if ($marker === 0xDA || $marker === 0xD9) { // começo da imagem: não há mais metadados
                    return false;
                }
                $length = unpack('n', substr($head, $offset + 2, 2))[1];
                if ($marker === 0xE1) {
                    return true;
                }
                $offset += 2 + $length;
            }
            return false;
        }
        if (str_starts_with($head, "\x89PNG\r\n\x1A\n")) {
            // Os blocos de texto podem vir depois da imagem (IDAT): percorre o arquivo todo pelos cabeçalhos dos blocos.
            $handle = @fopen($file, 'rb');
            if ($handle === false) {
                return false;
            }
            try {
                fseek($handle, 8);
                while (strlen($chunk = (string)fread($handle, 8)) === 8) {
                    $length = unpack('N', substr($chunk, 0, 4))[1];
                    $type = substr($chunk, 4, 4);
                    if (in_array($type, ['eXIf', 'tEXt', 'iTXt', 'zTXt'], true)) {
                        return true;
                    }
                    if ($type === 'IEND' || fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                        return false;
                    }
                }
                return false;
            } finally {
                fclose($handle);
            }
        }
        if (str_starts_with($head, 'RIFF') && substr($head, 8, 4) === 'WEBP') {
            return str_contains($head, 'EXIF') || str_contains($head, 'XMP ');
        }
        return false;
    }

    /**
     * Limpa as imagens antigas de uma pasta (ADR 0390): só JPEG, PNG e WebP com metadados. Com $apply = false só conta.
     *
     * @return array{checked: int, with_metadata: int, cleaned: int, failed: list<string>}
     */
    public static function cleanFolder(string $root, bool $apply): array
    {
        $result = ['checked' => 0, 'with_metadata' => 0, 'cleaned' => 0, 'failed' => []];
        if (!is_dir($root)) {
            return $result;
        }
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $mime = $types[strtolower($file->getExtension())] ?? null;
            if (!$file->isFile() || $file->isLink() || $mime === null) {
                continue;
            }
            $result['checked']++;
            $path = $file->getPathname();
            if (!self::hasMetadata($path)) {
                continue;
            }
            $result['with_metadata']++;
            if (!$apply) {
                continue;
            }
            $detected = strtolower((string)(new \finfo(FILEINFO_MIME_TYPE))->file($path));
            if ($detected === $mime && self::strip($path, $mime)) {
                $result['cleaned']++;
            } else {
                $result['failed'][] = substr($path, strlen(rtrim($root, '/')) + 1);
            }
        }
        return $result;
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

    /** Orientação do EXIF (1 a 8). Usa a extensão exif quando o PHP tem; senão lê o segmento APP1 direto (o PHP do lab não tem exif). */
    public static function orientation(string $file): int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($file);
            return (int)(is_array($exif) ? ($exif['Orientation'] ?? 1) : 1);
        }
        $bytes = @file_get_contents($file, false, null, 0, 262144);
        return is_string($bytes) ? self::jpegOrientation($bytes) : 1;
    }

    /** Lê a tag Orientation (0x0112) do IFD0 do EXIF de um JPEG em bytes; 1 quando não há. */
    public static function jpegOrientation(string $bytes): int
    {
        if (!str_starts_with($bytes, "\xFF\xD8")) {
            return 1;
        }
        $pos = 2;
        $len = strlen($bytes);
        while ($pos + 4 <= $len && $bytes[$pos] === "\xFF") {
            $marker = ord($bytes[$pos + 1]);
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }
            $size = unpack('n', substr($bytes, $pos + 2, 2))[1];
            if ($size < 2) {
                break;
            }
            if ($marker === 0xE1 && substr($bytes, $pos + 4, 6) === "Exif\0\0") {
                return self::tiffOrientation(substr($bytes, $pos + 10, $size - 8));
            }
            $pos += 2 + $size;
        }
        return 1;
    }

    private static function tiffOrientation(string $tiff): int
    {
        $order = substr($tiff, 0, 2);
        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';
        $read = static function (string $format, int $at, int $size) use ($tiff): ?int {
            $chunk = substr($tiff, $at, $size);
            return strlen($chunk) === $size ? (int)unpack($format, $chunk)[1] : null;
        };
        $ifd = $read($long, 4, 4);
        $count = $ifd === null ? null : $read($short, $ifd, 2);
        for ($i = 0; $count !== null && $i < min($count, 200); $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($read($short, $entry, 2) === 0x0112) {
                $value = $read($short, $entry + 8, 2);
                return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
            }
        }
        return 1;
    }

    private static function orient(\GdImage $image, string $file): \GdImage
    {
        $angle = self::angle(self::orientation($file));
        if ($angle === 0) {
            return $image;
        }
        $rotated = imagerotate($image, $angle, 0);
        return $rotated === false ? $image : $rotated;
    }
}
