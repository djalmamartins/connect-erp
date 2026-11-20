<?php

declare(strict_types=1);

namespace Source\Services\Erp;

/** Erro de regra do Meu Dia (ADR 0630) com o status HTTP, usado igual pela tela e pela API. */
final class MyDayError extends \RuntimeException
{
    /** @param array<string, string> $errors campo => mensagem */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $errors = []
    ) {
        parent::__construct($message);
    }

    /** @param array<string, string> $errors */
    public static function invalid(array $errors, string $message = 'Revise os campos destacados.'): self
    {
        return new self('validation_failed', $message, 422, $errors);
    }
}
