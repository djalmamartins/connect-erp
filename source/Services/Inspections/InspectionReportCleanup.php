<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

use PDO;
use Source\Core\Connect;

/**
 * Limpeza dos PDFs do relatório de vistoria (ADR 0335). O relatório é gerado na hora do envio (InspectionReport::queue) e
 * gravado só para ir como anexo da fila de e-mail (mail_queue), que lê o arquivo no envio e nas novas tentativas. Depois que
 * nenhum e-mail ainda por sair aponta para ele, o arquivo não serve para mais nada: reenviar gera um PDF novo.
 *
 * Regra pura removable(): apaga um PDF de <fotos>/reports/ quando ele tem mais de MIN_AGE segundos e não está em nenhum
 * e-mail pendente, em nova tentativa ou em envio. Só mexe em arquivos .pdf dentro de reports/: nunca nas fotos.
 */
final class InspectionReportCleanup
{
    public const MIN_AGE = 86400;

    private PDO $pdo;
    private string $directory;

    public function __construct(?PDO $pdo = null, ?string $photosRoot = null)
    {
        $this->pdo = $pdo ?? Connect::getInstance();
        $this->directory = rtrim($photosRoot ?? (new PhotoStorage())->root(), '/') . '/reports';
    }

    /**
     * @param array<string, int> $files caminho => mtime
     * @param array<string, true> $referenced caminhos ainda anexados a e-mail por sair
     * @return list<string>
     */
    public static function removable(array $files, array $referenced, int $now, int $minAge = self::MIN_AGE): array
    {
        $out = [];
        foreach ($files as $path => $mtime) {
            if (!str_ends_with(strtolower($path), '.pdf') || isset($referenced[$path]) || $now - $mtime < $minAge) {
                continue;
            }
            $out[] = $path;
        }
        sort($out);
        return $out;
    }

    /** @return array<string, true> */
    public function referenced(): array
    {
        $rows = $this->pdo->query("SELECT attachments_json FROM mail_queue WHERE status IN ('pending','retry','processing') AND sent_at IS NULL AND cancelled_at IS NULL AND attachments_json IS NOT NULL")
            ->fetchAll(PDO::FETCH_COLUMN);
        $paths = [];
        foreach ($rows as $json) {
            $list = json_decode((string)$json, true);
            foreach (is_array($list) ? array_keys($list) : [] as $path) {
                if (is_string($path)) {
                    $paths[$path] = true;
                }
            }
        }
        return $paths;
    }

    /** @return array{removed:int, kept:int, bytes:int} */
    public function run(?int $now = null, bool $dryRun = false): array
    {
        $files = [];
        if (is_dir($this->directory)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if ($file->isFile() && !$file->isLink()) {
                    $files[$file->getPathname()] = $file->getMTime();
                }
            }
        }
        $remove = self::removable($files, $files === [] ? [] : $this->referenced(), $now ?? time());
        $bytes = 0;
        $removed = 0;
        foreach ($remove as $path) {
            $size = (int)@filesize($path);
            if ($dryRun || @unlink($path)) {
                $bytes += $size;
                $removed++;
            }
        }
        return ['removed' => $removed, 'kept' => count($files) - $removed, 'bytes' => $bytes];
    }
}
