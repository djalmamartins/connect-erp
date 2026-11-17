<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/**
 * Copia a árvore de uma pasta local para outro armazenamento (ADR 0376): os arquivos que já estão no servidor vão para o
 * OneDrive antes de trocar DOCUMENT_STORAGE. Pode ser interrompida e rodada de novo: o que já subiu fica anotado e não
 * sobe outra vez. Não apaga nada da pasta local.
 */
final class StorageCopy
{
    /**
     * Caminhos relativos a copiar: só arquivos comuns, sem links, sem o .htaccess de defesa e sem ponto no começo.
     *
     * @return list<string>
     */
    public static function files(string $root): array
    {
        $root = rtrim($root, '/');
        if (!is_dir($root)) {
            return [];
        }
        $out = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            /** @var \SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen($root) + 1);
            if ($file->isLink() || !$file->isFile() || str_starts_with(basename($relative), '.') || !LocalDocumentStore::safe($relative)) {
                continue;
            }
            $out[] = $relative;
        }
        sort($out);
        return $out;
    }

    /**
     * @return array{total: int, copied: int, skipped: int, failed: list<string>}
     */
    public static function run(string $root, DocumentStore $target, string $doneFile, bool $dryRun): array
    {
        $done = is_file($doneFile) ? array_flip(array_filter(array_map('trim', (array)file($doneFile)))) : [];
        $result = ['total' => 0, 'copied' => 0, 'skipped' => 0, 'failed' => []];
        foreach (self::files($root) as $relative) {
            $result['total']++;
            if (isset($done[$relative])) {
                $result['skipped']++;
                continue;
            }
            if ($dryRun) {
                continue;
            }
            if ($target->put($relative, rtrim($root, '/') . '/' . $relative, false)) {
                file_put_contents($doneFile, $relative . "\n", FILE_APPEND | LOCK_EX);
                $result['copied']++;
            } else {
                $result['failed'][] = $relative;
            }
        }
        return $result;
    }
}
