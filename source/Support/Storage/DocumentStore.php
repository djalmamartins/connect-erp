<?php

declare(strict_types=1);

namespace Source\Support\Storage;

/**
 * Onde os arquivos enviados ficam guardados (ADR 0370).
 *
 * Hoje só existe o disco local (LocalDocumentStore). A ida dos documentos para o OneDrive da Connect (ROADMAP 3.5) entra
 * como outra implementação desta interface, sem mudar quem usa: quem precisa do arquivo pede path() e recebe um caminho
 * local legível (no OneDrive, uma cópia baixada para o cache). O caminho relativo é validado por quem chama.
 */
interface DocumentStore
{
    /** Guarda $source em $relative. $move: o arquivo veio de upload HTTP e pode ser movido. */
    public function put(string $relative, string $source, bool $move): bool;

    /** Caminho local legível do arquivo, ou null quando não existe. */
    public function path(string $relative): ?string;

    /** Apaga o arquivo; não reclama se ele já não existe. */
    public function delete(string $relative): void;
}
