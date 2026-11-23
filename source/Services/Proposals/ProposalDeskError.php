<?php

declare(strict_types=1);

namespace Source\Services\Proposals;

/** Erro de regra das propostas, com o código e o status HTTP que a API devolve. */
final class ProposalDeskError extends \RuntimeException
{
    /** @param array<string,string> $fields */
    public function __construct(public readonly string $errorCode, string $message, public readonly int $status, public readonly array $fields = [])
    {
        parent::__construct($message);
    }
}
