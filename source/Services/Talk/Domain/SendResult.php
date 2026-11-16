<?php
declare(strict_types=1);

namespace Source\Services\Talk\Domain;

final class SendResult
{
    public function __construct(public readonly string $providerMessageId)
    {
    }
}
