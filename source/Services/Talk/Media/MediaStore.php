<?php
declare(strict_types=1);

namespace Source\Services\Talk\Media;

use Source\Services\Talk\Service\DomainError;

/**
 * Guarda os arquivos das conversas fora da pasta pública (storage/media/AAAA/MM/<aleatório>.<ext>).
 * Só aceita os tipos que o WhatsApp entrega; o tipo é conferido pelo conteúdo, não pelo nome.
 * Quem baixa passa pelo /media/{id}, que confere o acesso à conversa.
 */
final class MediaStore
{
    /** mime => [extensão, tipo da mensagem] */
    public const ALLOWED = [
        'image/jpeg' => ['jpg', 'image'], 'image/png' => ['png', 'image'], 'image/webp' => ['webp', 'image'],
        'audio/ogg' => ['ogg', 'audio'], 'audio/mpeg' => ['mp3', 'audio'], 'audio/mp4' => ['m4a', 'audio'],
        'audio/aac' => ['aac', 'audio'], 'audio/amr' => ['amr', 'audio'], 'audio/webm' => ['weba', 'audio'],
        'video/mp4' => ['mp4', 'video'], 'video/3gpp' => ['3gp', 'video'],
        'application/pdf' => ['pdf', 'document'],
        'application/msword' => ['doc', 'document'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['docx', 'document'],
        'application/vnd.ms-excel' => ['xls', 'document'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['xlsx', 'document'],
        'text/plain' => ['txt', 'document'], 'text/csv' => ['csv', 'document'],
    ];
    public const MAX_BYTES = 16 * 1024 * 1024; // limite do WhatsApp para mídia (documento vai até 100 MB, mas 16 MB basta)

    public function __construct(private string $root)
    {
    }

    /** @return array{path:string, mime:string, type:string, size:int, name:string} */
    public function save(string $bytes, ?string $declaredMime = null, ?string $originalName = null): array
    {
        $size = strlen($bytes);
        if ($size === 0) {
            throw new DomainError('Arquivo vazio.');
        }
        if ($size > self::MAX_BYTES) {
            throw new DomainError('Arquivo maior que 16 MB.');
        }
        $mime = $this->detect($bytes, $declaredMime);
        if (!isset(self::ALLOWED[$mime])) {
            throw new DomainError('Tipo de arquivo não aceito. Envie imagem, áudio, vídeo, PDF, Word, Excel ou texto.');
        }
        [$ext, $type] = self::ALLOWED[$mime];
        $rel = 'media/' . date('Y/m') . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $abs = $this->root . '/' . $rel;
        if (!is_dir(dirname($abs)) && !mkdir(dirname($abs), 0770, true) && !is_dir(dirname($abs))) {
            throw new \RuntimeException('Não consegui criar a pasta de arquivos.');
        }
        if (file_put_contents($abs, $bytes, LOCK_EX) !== $size) {
            throw new \RuntimeException('Não consegui gravar o arquivo.');
        }
        return ['path' => $rel, 'mime' => $mime, 'type' => $type, 'size' => $size, 'name' => self::cleanName($originalName, $ext)];
    }

    /** Caminho absoluto de um arquivo guardado (recusa qualquer coisa fora de media/). */
    public function absolute(string $rel): string
    {
        if (!preg_match('#^media/\d{4}/\d{2}/[a-f0-9]{32}\.[a-z0-9]{2,5}$#', $rel)) {
            throw new DomainError('Arquivo inválido.', 404);
        }
        $abs = $this->root . '/' . $rel;
        if (!is_file($abs)) {
            throw new DomainError('Arquivo não encontrado.', 404);
        }
        return $abs;
    }

    public static function cleanName(?string $name, string $ext): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F\/\\\\"<>:|?*]+/u', '', (string) $name) ?? '');
        if ($name === '') {
            $name = 'arquivo-' . date('Ymd-His') . '.' . $ext;
        }
        return mb_substr($name, 0, 120);
    }

    private function detect(string $bytes, ?string $declared): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: 'application/octet-stream';
        $declared = $declared ? strtolower(trim(explode(';', $declared)[0])) : null;
        // Office moderno é um zip; áudio do WhatsApp às vezes vem como "video/ogg" ou "application/ogg"
        if (in_array($mime, ['application/zip', 'application/octet-stream'], true) && $declared && str_starts_with($declared, 'application/vnd.openxmlformats')) {
            return $declared;
        }
        if (in_array($mime, ['video/ogg', 'application/ogg'], true)) {
            return 'audio/ogg';
        }
        if ($mime === 'video/webm' && $declared && str_starts_with($declared, 'audio/')) {
            return 'audio/webm';
        }
        if ($mime === 'video/mp4' && $declared === 'audio/mp4') {
            return 'audio/mp4';
        }
        if ($mime === 'application/vnd.ms-office' || $mime === 'application/CDFV2') {
            return $declared && isset(self::ALLOWED[$declared]) ? $declared : 'application/msword';
        }
        return $mime;
    }
}
