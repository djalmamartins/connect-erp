<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

/**
 * Banco que simula o pagamento de um boleto no ambiente de testes (Inter, ADR 0156; Cora, ADR 0322).
 * Só existe em adaptador preso ao sandbox: o adaptador recusa a URL de produção no construtor.
 * A baixa continua vindo da consulta (getCharge), nunca desta chamada.
 */
interface BankPaymentSimulator
{
    /** @param string $method BOLETO ou PIX. Recusa do banco = InvalidArgumentException; sem resposta = RuntimeException. */
    public function simulatePayment(?string $externalId, string $method = 'BOLETO'): void;

    /** Só pede o token (teste da credencial e do certificado), sem emitir nada. */
    public function checkAuthentication(): void;
}
