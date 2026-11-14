<?php

declare(strict_types=1);

namespace Source\Services\Resident;

/** Resposta do morador a um chamado já fechado (ADR 0305): a API devolve 409 ticket_closed. */
final class ResidentTicketClosed extends \DomainException
{
}
