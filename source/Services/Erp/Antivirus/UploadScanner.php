<?php

declare(strict_types=1);

namespace Source\Services\Erp\Antivirus;

/**
 * Verificação de arquivo enviado antes de ir para o armazenamento privado (ADR 0098).
 * scan() devolve um dos status de ScanStatus: clean, infected, unscanned ou error. Nunca lança exceção:
 * qualquer falha vira error, e a política (UploadScanPolicy) decide se o envio é aceito.
 */
interface UploadScanner
{
    public function scan(string $path): string;

    /** Está ligado (tem para onde mandar o arquivo)? NullScanner sempre false. */
    public function available(): bool;
}
