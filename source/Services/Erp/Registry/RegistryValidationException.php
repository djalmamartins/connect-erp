<?php

declare(strict_types=1);

namespace Source\Services\Erp\Registry;

use RuntimeException;

/** Entrada recusada; $errors traz a mensagem de cada campo (chave = nome do campo). */
final class RegistryValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Dados inválidos.');
    }
}
