<?php

declare(strict_types=1);

namespace Source\Services;

use InvalidArgumentException;

/** Dados inválidos para um item do checklist; $errors é campo => mensagem. */
final class OnboardingValidationError extends InvalidArgumentException
{
    /** @param array<string, string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('Dados inválidos para o item do checklist.');
    }
}
