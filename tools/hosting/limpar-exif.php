<?php

declare(strict_types=1);

/**
 * Tira o EXIF (GPS, data, aparelho) das imagens já gravadas na pasta pública storage/images (ADR 0390). As imagens
 * novas já saem limpas desde o ADR 0389; este comando é para as antigas, por exemplo as do site levadas por
 * tools/migrate-legacy-images.sh.
 *
 *   php tools/hosting/limpar-exif.php            conta quantas imagens têm metadados, sem mudar nada
 *   php tools/hosting/limpar-exif.php --aplicar  regrava só essas, sem os metadados
 *
 * Saída 0: contagem feita ou tudo limpo; 1: alguma imagem não pôde ser regravada (lista na tela).
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use Source\Support\ImageMetadata;

$apply = in_array('--aplicar', $argv, true);
$folder = dirname(__DIR__, 2) . '/storage/images';
$result = ImageMetadata::cleanFolder($folder, $apply);

echo "Imagens conferidas em storage/images: {$result['checked']}\n";
echo "Com metadados (EXIF, XMP ou texto do PNG): {$result['with_metadata']}\n";
if (!$apply) {
    if ($result['with_metadata'] > 0) {
        echo "Para limpar: php tools/hosting/limpar-exif.php --aplicar\n";
    }
    exit(0);
}
echo "Limpas: {$result['cleaned']}\n";
foreach ($result['failed'] as $path) {
    echo "Não foi possível limpar: {$path}\n";
}
exit($result['failed'] === [] ? 0 : 1);
