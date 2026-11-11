<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

/**
 * Capacidades opcionais de um adaptador (ADR 0156), fora do BankAdapter para não quebrar os
 * adaptadores existentes. Quem não implementa simplesmente não oferece o recurso.
 */
interface BankChargeExtras
{
    /** PDF do boleto (binário). Erro do banco = RuntimeException. */
    public function downloadBoleto(string $ourNumber, ?string $externalId): string;

    /**
     * Lê o corpo de uma notificação do banco e devolve só os identificadores dos títulos citados.
     * O conteúdo NÃO é confiável: quem chama consulta cada título no banco antes de mudar qualquer
     * estado (consulta na notificação). Corpo malformado = InvalidArgumentException.
     *
     * @return list<array{external_id:string, our_number:?string}>
     */
    public function parseWebhook(string $body): array;
}
