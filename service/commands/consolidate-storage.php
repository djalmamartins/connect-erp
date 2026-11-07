<?php

declare(strict_types=1);

/** Consolidate generated runtime files under storage/. */

$root = dirname(__DIR__, 2);
$storage = $root . '/storage';

foreach (['backups', 'cache', 'database', 'logs', 'output', 'sessions', 'temp', 'uploads'] as $name) {
    $directory = $storage . '/' . $name;
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Não foi possível criar {$directory}");
    }
}

$moveTree = static function (string $source, string $target) use (&$moveTree): void {
    if (!is_dir($source)) {
        return;
    }
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Não foi possível criar {$target}");
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) {
            continue;
        }
        $destination = $target . '/' . $item->getFilename();
        if ($item->isDir()) {
            $moveTree($item->getPathname(), $destination);
            @rmdir($item->getPathname());
        } elseif (file_exists($destination) || !rename($item->getPathname(), $destination)) {
            throw new RuntimeException("Não foi possível consolidar {$item->getPathname()}");
        }
    }
};

$moveTree($root . '/output', $storage . '/output');
$moveTree($root . '/tmp', $storage . '/temp');
@rmdir($root . '/output');
@rmdir($root . '/tmp');

fwrite(STDOUT, "Arquivos gerados consolidados em {$storage}\n");
