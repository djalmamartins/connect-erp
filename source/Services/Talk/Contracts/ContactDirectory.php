<?php
declare(strict_types=1);

namespace Source\Services\Talk\Contracts;

/**
 * Busca no cadastro do ERP quem é o dono do telefone (morador, síndico, fornecedor...).
 * Implementação real lê as tabelas do ERP; ver docs/integracao-erp.md.
 */
interface ContactDirectory
{
    /**
     * @param list<string> $phoneCandidates variações do número (com/sem DDI, com/sem 9)
     * @return array{person_id:int, name:string, details:array<string,mixed>}|null
     *         details: ex. ['condominio' => 'Res. Jardins', 'unidade' => 'Bloco A 101', 'tipo' => 'morador']
     */
    public function findByPhone(array $phoneCandidates): ?array;
}
