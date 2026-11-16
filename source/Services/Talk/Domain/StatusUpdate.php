<?php
declare(strict_types=1);

namespace Source\Services\Talk\Domain;

/** Atualização de status (ack) de uma mensagem enviada. */
final class StatusUpdate
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly string $ack, // sent | delivered | read | failed
    ) {
    }
}
