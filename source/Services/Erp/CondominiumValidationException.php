<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use RuntimeException;

/** Erros de validação do cadastro de condomínio, por campo ('_form' para o formulário). */
final class CondominiumValidationException extends RuntimeException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Dados do condomínio inválidos.');
    }
}
