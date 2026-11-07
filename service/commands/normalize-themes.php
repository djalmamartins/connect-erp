<?php

declare(strict_types=1);

/** Normalize legacy theme directories and template references. */

$root = dirname(__DIR__, 2);
$container = $root . '/container';
$backup = $root . '/storage/backups/themes-before-normalization';

$copyTree = static function (string $source, string $target) use (&$copyTree): void {
    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException("Não foi possível criar {$target}");
    }
    foreach (new DirectoryIterator($source) as $item) {
        if ($item->isDot()) continue;
        $destination = $target . '/' . $item->getFilename();
        if ($item->isDir()) {
            $copyTree($item->getPathname(), $destination);
        } elseif (!copy($item->getPathname(), $destination)) {
            throw new RuntimeException("Não foi possível copiar {$item->getPathname()}");
        }
    }
};

if (!is_dir($backup)) {
    $copyTree($container, $backup);
}

$replacements = [
    'widgets/' => 'components/',
    '/widgets' => '/components',
    'views/' => 'pages/',
    '/views' => '/pages',
    '"_theme_error"' => '"layouts/error"',
    "'_theme_error'" => "'layouts/error'",
    '"_theme_sales"' => '"layouts/sales"',
    "'_theme_sales'" => "'layouts/sales'",
    '"_theme"' => '"layouts/theme"',
    "'_theme'" => "'layouts/theme'",
    '"_studio"' => '"layouts/studio"',
    "'_studio'" => "'layouts/studio'",
    '"_login"' => '"layouts/login"',
    "'_login'" => "'layouts/login'",
    '"_app"' => '"layouts/app"',
    "'_app'" => "'layouts/app'",
    '"_erp"' => '"layouts/erp"',
    "'_erp'" => "'layouts/erp'",
];

$roots = [$root . '/source', $container];
foreach ($roots as $scanRoot) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($scanRoot, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $content = file_get_contents($file->getPathname());
        $updated = str_replace(array_keys($replacements), array_values($replacements), $content);
        if ($updated !== $content) {
            file_put_contents($file->getPathname(), $updated);
        }
    }
}

$directories = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($container, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($iterator as $item) {
    if ($item->isDir() && in_array($item->getFilename(), ['views', 'widgets'], true)) {
        $directories[] = $item->getPathname();
    }
}
foreach ($directories as $directory) {
    $targetName = basename($directory) === 'views' ? 'pages' : 'components';
    $target = dirname($directory) . '/' . $targetName;
    if (file_exists($target) || !rename($directory, $target)) {
        throw new RuntimeException("Não foi possível renomear {$directory}");
    }
}

$layoutFiles = [
    '_theme.php' => 'theme.php', '_theme_error.php' => 'error.php', '_theme_sales.php' => 'sales.php',
    '_studio.php' => 'studio.php', '_login.php' => 'login.php', '_app.php' => 'app.php', '_erp.php' => 'erp.php',
];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($container, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile() || !isset($layoutFiles[$file->getFilename()])) continue;
    $layouts = $file->getPath() . '/layouts';
    if (!is_dir($layouts) && !mkdir($layouts, 0775, true) && !is_dir($layouts)) {
        throw new RuntimeException("Não foi possível criar {$layouts}");
    }
    $target = $layouts . '/' . $layoutFiles[$file->getFilename()];
    if (!rename($file->getPathname(), $target)) {
        throw new RuntimeException("Não foi possível mover {$file->getPathname()}");
    }
}

fwrite(STDOUT, "Themes normalizados; backup em {$backup}\n");
