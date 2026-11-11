<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use RuntimeException;

/** Fechamento em lote recusado (ADR 0205): nenhum condomínio foi fechado; refused lista cada um com os motivos. */
final class MonthClosingBatchRefused extends RuntimeException
{
    /** @param list<array{condominium_id:int, name:string, reasons:list<array{code:string, level:string, message:string}>}> $refused */
    public function __construct(public readonly array $refused)
    {
        parent::__construct('Fechamento recusado: nenhum condomínio foi fechado.');
    }
}
