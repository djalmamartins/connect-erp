<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

/** Padrão sem antivírus configurado (ADR 0098): não verifica, só devolve "não verificado". */
final class NullScanner implements UploadScanner
{
    public function scan(string $path): string
    {
        return ScanStatus::UNSCANNED;
    }

    public function available(): bool
    {
        return false;
    }
}
