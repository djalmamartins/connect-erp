<?php
declare(strict_types=1);

namespace Source\Services\Talk\Contracts;

/**
 * Ponte com o helpdesk do ERP. Cada conversa vira (ou se liga a) um chamado.
 * Implementação real grava nas tabelas de chamados do ERP.
 */
interface Helpdesk
{
    /**
     * Abre o chamado da conversa. Devolve o id e o número do chamado no helpdesk
     * (é esse número que o cliente vê), ou null quando não há helpdesk ligado.
     * @return array{id:int, protocol:?string}|null
     */
    public function open(array $conversation, array $contact, ?string $sectorName): ?array;

    public function transfer(int $ticketId, string $toSectorName, int $byUserId, ?string $note): void;

    public function close(int $ticketId, int $byUserId, ?string $resolution): void;
}
