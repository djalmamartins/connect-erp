<?php

declare(strict_types=1);

namespace Source\Services\Support;

/** Erro de validação ou de escopo na abertura de chamado; field aponta o campo do formulário. */
final class TicketError extends \RuntimeException
{
    public function __construct(public readonly string $field, string $message, int $status = 422)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->getCode() ?: 422;
    }
}
