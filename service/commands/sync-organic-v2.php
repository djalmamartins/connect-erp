<?php

declare(strict_types=1);

/**
 * Synchronize the official Organic V2 distribution into Moves.
 *
 * Usage: php service/commands/sync-organic-v2.php [organic-v2-path]
 */

$movesRoot = dirname(__DIR__, 2);
$sourceRoot = $argv[1] ?? dirname($movesRoot) . '/organic-v2';
$sourceRoot = realpath($sourceRoot) ?: $sourceRoot;
$targetRoot = $movesRoot . '/organic';

$files = [
    'dist/organic.css' => 'organic.css',
    'dist/organic.min.css' => 'organic.min.css',
    'dist/organic.js' => 'organic.js',
    'dist/organic.min.js' => 'organic.min.js',
    'dist/organic.global.js' => 'organic.global.js',
    'dist/organic.global.min.js' => 'organic.global.min.js',
    'src/css/compat-v1.css' => 'compat-v1.css',
    'src/js/compat-v1.js' => 'compat-v1.js',
];

foreach ($files as $source => $target) {
    $from = $sourceRoot . '/' . $source;
    $to = $targetRoot . '/' . $target;

    if (!is_file($from)) {
        fwrite(STDERR, "Organic V2 artifact not found: {$from}\n");
        exit(1);
    }

    $temporary = $to . '.tmp';
    if (!copy($from, $temporary) || !rename($temporary, $to)) {
        @unlink($temporary);
        fwrite(STDERR, "Unable to synchronize: {$to}\n");
        exit(1);
    }
}

$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    if (!is_dir($to) && !mkdir($to, 0775, true) && !is_dir($to)) {
        throw new RuntimeException("Unable to create directory: {$to}");
    }

    foreach (new DirectoryIterator($from) as $item) {
        if ($item->isDot()) {
            continue;
        }

        $target = $to . '/' . $item->getFilename();
        if ($item->isDir()) {
            $copyTree($item->getPathname(), $target);
        } elseif (!copy($item->getPathname(), $target)) {
            throw new RuntimeException("Unable to synchronize: {$target}");
        }
    }
};

try {
    $copyTree($sourceRoot . '/assets', $targetRoot . '/assets');
    $copyTree($sourceRoot . '/dist/fonts', $targetRoot . '/fonts');
    $copyTree($sourceRoot . '/src/js', $targetRoot . '/modules');
    $copyTree($sourceRoot . '/dist/organic-editor', $targetRoot . '/editor');
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}

file_put_contents(
    $targetRoot . '/VERSION',
    trim((string) file_get_contents($sourceRoot . '/VERSION')) . PHP_EOL
);

fwrite(STDOUT, "Organic V2 synchronized from {$sourceRoot} to {$targetRoot}\n");
