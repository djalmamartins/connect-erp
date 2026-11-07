<?php

declare(strict_types=1);

namespace Source\Services;

final class CondominiumAccessDenied extends \RuntimeException
{
    public function __construct(public readonly int $condominiumId)
    {
        parent::__construct('Acesso negado a este condomínio.', 403);
    }
}
