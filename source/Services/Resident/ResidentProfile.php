<?php

declare(strict_types=1);

namespace Source\Services\Resident;

use PDO;
use Source\Core\Connect;
use Source\Services\Erp\Registry\RegistryValidationException;
use Source\Support\Audit;
use Source\Support\Storage\DocumentStore;
use Source\Support\Storage\DocumentStores;
use Source\Support\Storage\LocalDocumentStore;

/**
 * Boas-vindas e foto do morador no aplicativo (revisão de 15/11, ADR 0302), iguais no web e no celular.
 *
 * É uma linha por conta em erp_resident_app_profiles. A foto nunca é gravada como veio:
 * - o tipo é conferido pelo conteúdo;
 * - a imagem é decodificada pelo GD e girada pela orientação EXIF;
 * - é recortada em quadrado no centro e reduzida a 512 px;
 * - é regravada como JPEG, o que descarta o EXIF com GPS e o modelo do aparelho (LGPD).
 * Ela fica fora da pasta pública, e só a própria pessoa a vê, pela sessão ou pelo Bearer.
 * Grava, lê e apaga pelo DocumentStore (ADR 0372), como o Disco Virtual (ADR 0370).
 */
final class ResidentProfile
{
    public const MAX_BYTES = 10485760; // 10 MB
    public const SIDE = 512;
    private const QUALITY = 85;
    private const ALLOWED = ['image/jpeg', 'image/png', 'image/webp'];
    private const MAX_PIXELS = 50000000;

    private PDO $pdo;
    private DocumentStore $store;

    public function __construct(?PDO $pdo = null, ?string $root = null, ?DocumentStore $store = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $local = dirname(__DIR__, 3) . '/storage/private/resident-photos';
        $this->store = $store ?? ($root !== null ? new LocalDocumentStore($root) : DocumentStores::make('resident-photos', $local));
    }

    /** @return array{completed: bool, has_photo: bool} */
    public function status(int $userId): array
    {
        $row = $this->row($userId);
        return ['completed' => $row !== null && $row['onboarded_at'] !== null, 'has_photo' => $this->photoFile($row) !== null];
    }

    /** Marca as boas-vindas como vistas (concluídas ou puladas). Repetir não muda a data. */
    public function complete(int $userId): void
    {
        $this->pdo->prepare('INSERT INTO erp_resident_app_profiles (user_id, onboarded_at) VALUES (:user, NOW())
            ON DUPLICATE KEY UPDATE onboarded_at = COALESCE(onboarded_at, NOW())')->execute(['user' => $userId]);
    }

    /**
     * @param array{tmp_name?: string, error?: int, size?: int} $upload item de $_FILES
     * @throws RegistryValidationException foto ausente, grande demais ou num formato que não é imagem
     */
    public function storePhoto(int $userId, array $upload): void
    {
        $image = $this->decode($upload);
        $relative = sprintf('%d/%s.jpg', $userId, bin2hex(random_bytes(16)));
        $encoded = tempnam(sys_get_temp_dir(), 'photo');
        try {
            $written = $encoded !== false && imagejpeg($image, $encoded, self::QUALITY);
            unset($image);
            if (!$written || !$this->store->put($relative, $encoded, false)) {
                throw new \RuntimeException('Não foi possível guardar a foto.');
            }
        } finally {
            if ($encoded !== false) {
                @unlink($encoded);
            }
        }
        $old = $this->photoRelative($this->row($userId));
        $this->pdo->prepare('INSERT INTO erp_resident_app_profiles (user_id, photo_path) VALUES (:user, :path)
            ON DUPLICATE KEY UPDATE photo_path = VALUES(photo_path)')->execute(['user' => $userId, 'path' => $relative]);
        if ($old !== null) {
            $this->store->delete($old);
        }
        Audit::record('resident_app.photo', 'erp_resident_app_profiles', $userId, null, ['action' => 'store']);
    }

    /** Caminho absoluto da foto da conta, ou null. */
    public function photo(int $userId): ?string
    {
        return $this->photoFile($this->row($userId));
    }

    public function deletePhoto(int $userId): void
    {
        $row = $this->row($userId);
        $relative = $this->photoFile($row) !== null ? $this->photoRelative($row) : null;
        $this->pdo->prepare('UPDATE erp_resident_app_profiles SET photo_path = NULL WHERE user_id = :user')->execute(['user' => $userId]);
        if ($relative !== null) {
            $this->store->delete($relative);
            Audit::record('resident_app.photo', 'erp_resident_app_profiles', $userId, null, ['action' => 'delete']);
        }
    }

    /**
     * Conta excluída (ADR 0377, LGPD): apaga a foto do armazenamento e a linha do perfil. Sem foto, só a linha.
     */
    public function forgetAccount(int $userId): void
    {
        $row = $this->row($userId);
        if ($row === null) {
            return;
        }
        $relative = $this->photoRelative($row);
        if ($relative !== null) {
            $this->store->delete($relative);
        }
        $this->pdo->prepare('DELETE FROM erp_resident_app_profiles WHERE user_id = :user')->execute(['user' => $userId]);
    }

    /** Recorte central quadrado (regra pura): [x, y, lado] da área da imagem original que vira a foto. */
    public static function squareCrop(int $width, int $height): array
    {
        $side = min($width, $height);
        return [intdiv($width - $side, 2), intdiv($height - $side, 2), $side];
    }

    private function row(int $userId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT photo_path, onboarded_at FROM erp_resident_app_profiles WHERE user_id = :user');
        $stmt->execute(['user' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** Caminho relativo guardado, só no formato que o próprio serviço cria. */
    private function photoRelative(?array $row): ?string
    {
        $relative = (string)($row['photo_path'] ?? '');
        return preg_match('~^\d+/[a-f0-9]{32}\.jpg$~', $relative) ? $relative : null;
    }

    private function photoFile(?array $row): ?string
    {
        $relative = $this->photoRelative($row);
        return $relative === null ? null : $this->store->path($relative);
    }

    private function decode(array $upload): \GdImage
    {
        $temporary = (string)($upload['tmp_name'] ?? '');
        $error = (int)($upload['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
            throw new RegistryValidationException(['photo' => 'A foto passa do tamanho máximo de 10 MB.']);
        }
        if ($error !== UPLOAD_ERR_OK || $temporary === '' || !is_file($temporary)) {
            throw new RegistryValidationException(['photo' => 'Escolha uma foto.']);
        }
        $size = (int)filesize($temporary);
        if ($size < 1 || $size > self::MAX_BYTES) {
            throw new RegistryValidationException(['photo' => 'A foto passa do tamanho máximo de 10 MB.']);
        }
        $mime = strtolower((string)(new \finfo(FILEINFO_MIME_TYPE))->file($temporary));
        $info = @getimagesize($temporary);
        if (!in_array($mime, self::ALLOWED, true) || !$info || strtolower((string)$info['mime']) !== $mime) {
            throw new RegistryValidationException(['photo' => 'Envie uma foto em JPG, PNG ou WebP.']);
        }
        if ((int)$info[0] * (int)$info[1] > self::MAX_PIXELS) {
            throw new RegistryValidationException(['photo' => 'A foto tem resolução grande demais.']);
        }
        $image = @imagecreatefromstring((string)file_get_contents($temporary));
        if ($image === false) {
            throw new RegistryValidationException(['photo' => 'Não foi possível ler a foto.']);
        }
        $image = $this->orient($image, $mime, $temporary);
        [$x, $y, $side] = self::squareCrop(imagesx($image), imagesy($image));
        $target = min(self::SIDE, $side);
        $canvas = imagecreatetruecolor($target, $target);
        imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $image, 0, 0, $x, $y, $target, $target, $side, $side);
        unset($image);
        return $canvas;
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
        return $rotated === false ? $image : $rotated;
    }
}
