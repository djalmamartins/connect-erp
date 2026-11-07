<?php

namespace Source\Support;

use MovesCode\Storage\File;
use MovesCode\Storage\Image;
use MovesCode\Storage\Media;

/**
 * ERP | Class Upload
 *
 * @author Djalma Martins
 * @package Source\Support
 */
class Upload
{
    private const IMAGE_MAX_BYTES = 12582912;
    private const FILE_MAX_BYTES = 26214400;
    private const MEDIA_MAX_BYTES = 52428800;

    /** @var Message */
    private $message;

    /**
     * Upload constructor.
     */
    public function __construct()
    {
        $this->message = new Message();
    }

    /**
     * @return Message
     */
    public function message(): Message
    {
        return $this->message;
    }

    /**
     * @param array $image
     * @param string $name
     * @param int $width
     * @return null|string
     * @throws \Exception
     */
    public function image(array $image, string $name, int $width = CONF_IMAGE_SIZE): ?string
    {
        $upload = new Image(CONF_UPLOAD_DIR, CONF_UPLOAD_IMAGE_DIR);
        if (!$this->validUpload($image, $upload::isAllowed(), self::IMAGE_MAX_BYTES, true)) {
            $this->message->error("Você não selecionou uma imagem válida");
            return null;
        }

        return str_replace(CONF_UPLOAD_DIR . "/", "", $upload->upload($image, $name, $width, CONF_IMAGE_QUALITY));
    }

    /**
     * @param array $file
     * @param string $name
     * @return null|string
     * @throws \Exception
     */
    public function file(array $file, string $name): ?string
    {
        $upload = new File(CONF_UPLOAD_DIR, CONF_UPLOAD_FILE_DIR);
        if (!$this->validUpload($file, $upload::isAllowed(), self::FILE_MAX_BYTES)) {
            $this->message->error("Você não selecionou um arquivo válido");
            return null;
        }

        return str_replace(CONF_UPLOAD_DIR . "/", "", $upload->upload($file, $name));
    }

    /**
     * @param array $media
     * @param string $name
     * @return null|string
     * @throws \Exception
     */
    public function media(array $media, string $name): ?string
    {
        $upload = new Media(CONF_UPLOAD_DIR, CONF_UPLOAD_MEDIA_DIR);
        if (!$this->validUpload($media, $upload::isAllowed(), self::MEDIA_MAX_BYTES)) {
            $this->message->error("Você não selecionou uma mídia válida");
            return null;
        }

        return str_replace(CONF_UPLOAD_DIR . "/", "", $upload->upload($media, $name));
    }

    /**
     * @param string $filePath
     */
    public function remove(string $filePath): void
    {
        $candidate = realpath($filePath);
        $storage = realpath(dirname(__DIR__, 2) . '/' . CONF_UPLOAD_DIR);
        if ($candidate && $storage && is_file($candidate) && str_starts_with($candidate, $storage . DIRECTORY_SEPARATOR)) {
            unlink($candidate);
        }
    }

    private function validUpload(array &$upload, array $allowedMimes, int $maxBytes, bool $mustBeImage = false): bool
    {
        $temporary = (string)($upload['tmp_name'] ?? '');
        $error = (int)($upload['error'] ?? UPLOAD_ERR_OK);
        $size = is_file($temporary) ? (int)filesize($temporary) : 0;
        if ($error !== UPLOAD_ERR_OK || !$temporary || !is_file($temporary) || $size < 1 || $size > $maxBytes) {
            return false;
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = strtolower((string)$finfo->file($temporary));
        $allowedMimes = array_map('strtolower', $allowedMimes);
        if (!in_array($detectedMime, $allowedMimes, true)) {
            return false;
        }

        if ($mustBeImage) {
            $image = @getimagesize($temporary);
            if (!$image || empty($image['mime']) || strtolower((string)$image['mime']) !== $detectedMime) {
                return false;
            }
        }

        // O uploader de terceiros consulta este campo; substituímos o valor não confiável do navegador.
        $upload['type'] = $detectedMime;
        $upload['size'] = $size;
        return true;
    }
}
