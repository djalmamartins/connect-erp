<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/** Exclusão recusada: há cadastros ativos que apontam para este registro (ex.: unidade, ADR 0017). */
final class RegistryInUse extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Registro em uso por outros cadastros.');
    }
}
