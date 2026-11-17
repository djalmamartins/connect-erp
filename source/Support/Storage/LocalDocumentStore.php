<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/** Arquivos numa pasta privada do servidor (ADR 0370), como o Disco Virtual sempre fez (ADR 0047). */
final class LocalDocumentStore implements DocumentStore
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/');
    }

    public function root(): string
    {
        return $this->root;
    }

    public function put(string $relative, string $source, bool $move): bool
    {
        if (!self::safe($relative)) {
            return false;
        }
        $target = $this->root . '/' . $relative;
        $this->prepareDirectory(dirname($target));
        $stored = $move ? move_uploaded_file($source, $target) : copy($source, $target);
        if ($stored) {
            @chmod($target, 0640);
        }
        return $stored;
    }

    public function path(string $relative): ?string
    {
        if (!self::safe($relative)) {
            return null;
        }
        $path = $this->root . '/' . $relative;
        return is_file($path) ? $path : null;
    }

    public function delete(string $relative): void
    {
        $path = $this->path($relative);
        if ($path !== null) {
            @unlink($path);
        }
    }

    /** Sem caminho absoluto, sem "..", sem barra invertida nem byte nulo. */
    public static function safe(string $relative): bool
    {
        return $relative !== '' && $relative[0] !== '/' && !preg_match('~(^|/)\.\.(/|$)|[\\\\\x00]~', $relative);
    }

    private function prepareDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Não foi possível preparar a pasta dos arquivos.');
        }
        // Defesa extra caso a pasta storage fique dentro da raiz pública do Apache.
        $base = dirname($this->root);
        if (is_dir($base) && !is_file($base . '/.htaccess')) {
            @file_put_contents($base . '/.htaccess', "Require all denied\n");
        }
    }
}
