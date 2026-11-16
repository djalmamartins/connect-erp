<?php
declare(strict_types=1);

namespace Source\Services\Talk\Erp;

use Source\Services\Talk\Contracts\Helpdesk;

/** Sem helpdesk ligado: o protocolo do Talk é o registro do chamado. */
final class NullHelpdesk implements Helpdesk
{
    public function open(array $conversation, array $contact, ?string $sectorName): ?array
    {
        return null;
    }

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void
    {
    }

    public function close(int $ticketId, int $byUserId, ?string $resolution): void
    {
    }
}
