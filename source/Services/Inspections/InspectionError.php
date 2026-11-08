<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

/**
 * Erro de regra da vistoria com o status HTTP correspondente, usado igual pela tela e pela API.
 * not_found também cobre registros fora do escopo, para não revelar que existem.
 */
final class InspectionError extends \RuntimeException
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

    public static function notFound(string $what = 'Vistoria'): self
    {
        return new self('not_found', "{$what} não encontrada.", 404);
    }

    public static function forbidden(): self
    {
        return new self('forbidden', 'Sem permissão para esta ação.', 403);
    }

    /** @param array<string, string> $errors */
    public static function invalid(array $errors, string $message = 'Revise os campos destacados.'): self
    {
        return new self('validation_failed', $message, 422, $errors);
    }

    public static function conflict(string $message): self
    {
        return new self('conflict', $message, 409);
    }
}
