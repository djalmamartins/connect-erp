<?php
declare(strict_types=1);

namespace Source\Services\Talk\Domain;

/** Usuário do ERP no papel de atendimento. */
final class StaffMember
{
    /**
     * @param list<int> $agentSectors   setores onde atende
     * @param list<int> $managerSectors setores que gerencia (vê tudo do setor)
     */
    public function __construct(
        public readonly int $userId,
        public readonly bool $supervisor = false,
        public readonly array $agentSectors = [],
        public readonly array $managerSectors = [],
    ) {
    }

    public function sectors(): array
    {
        return array_values(array_unique([...$this->agentSectors, ...$this->managerSectors]));
    }
}
