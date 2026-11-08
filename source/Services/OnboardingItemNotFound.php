<?php

declare(strict_types=1);

namespace Source\Services;

use RuntimeException;

/** Item do checklist inexistente ou de outro condomínio (tratado como 404). */
final class OnboardingItemNotFound extends RuntimeException
{
    public function __construct(public readonly int $itemId)
    {
        parent::__construct("Item {$itemId} do checklist não encontrado.");
    }
}
