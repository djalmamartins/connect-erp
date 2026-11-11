<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use InvalidArgumentException;

/** Baixa em lote recusada (ADR 0191): nada foi gravado; problems diz o que impede cada conta. */
final class BatchSettlementRejected extends InvalidArgumentException
{
    /** @param list<array{entry_id:int, code:string, message:string}> $problems */
    public function __construct(public readonly array $problems)
    {
        parent::__construct(sprintf('%d conta(s) não podem ser baixadas: nada foi gravado.', count($problems)));
    }
}
