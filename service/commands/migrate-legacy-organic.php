<?php

declare(strict_types=1);

/**
 * Separate third-party libraries from Organic and archive the retired V1.
 * The operation is idempotent and keeps the retired files under storage/backups.
 */

$root = dirname(__DIR__, 2);
$organic = $root . '/organic';
$vendorAssets = $root . '/container/shared/assets/vendor';
$backup = $root . '/storage/backups/organic-v1-retired';

foreach ([$vendorAssets, $backup] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Não foi possível criar {$directory}");
    }
}

$move = static function (string $source, string $target): void {
    if (!file_exists($source)) {
        return;
    }
    if (file_exists($target)) {
        throw new RuntimeException("Destino já existe: {$target}");
    }
    if (!rename($source, $target)) {
        throw new RuntimeException("Não foi possível mover {$source} para {$target}");
    }
};

$move($organic . '/scripts', $vendorAssets . '/scripts');
$move($organic . '/owl', $vendorAssets . '/owl');

foreach (['css', 'js', 'styles', 'icons', 'README.md', '.DS_Store'] as $legacy) {
    $move($organic . '/' . $legacy, $backup . '/' . $legacy);
}

if (is_file($root . '/organic.zip')) {
    $move($root . '/organic.zip', $backup . '/organic.zip');
}

fwrite(STDOUT, "Bibliotecas terceiras separadas e Organic V1 arquivado em {$backup}\n");
