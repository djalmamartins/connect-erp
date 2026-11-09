<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/** Alteração recusada: o registro pertence a uma competência fechada (fechamento mensal, ADR 0031). */
final class RegistryLocked extends RuntimeException
{
    public function __construct(public readonly string $competence)
    {
        parent::__construct("A competência {$competence} está fechada. Reabra o fechamento mensal para alterar.");
    }
}
