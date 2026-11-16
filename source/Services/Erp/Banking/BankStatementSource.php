<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

/**
 * Banco que entrega o extrato e o saldo da conta pela API (Inter, ADR 0318). Só leitura: nada aqui
 * movimenta dinheiro. Quem não implementa continua com o extrato OFX enviado à mão (AP-140).
 */
interface BankStatementSource
{
    /** Período máximo de uma consulta de extrato, em dias (limite do banco). */
    public const MAX_DAYS = 90;

    /**
     * Lançamentos do extrato entre $from e $to (AAAA-MM-DD), no formato das linhas do OFX:
     * fitid estável (id da transação no banco), kind CREDIT/DEBIT, valor em centavos com sinal.
     *
     * @return list<array{fitid:string, kind:string, posted_on:string, amount_cents:int, memo:string}>
     */
    public function statement(string $from, string $to): array;

    /** Saldo da conta no fim do dia $on, em centavos (null = o banco não informou). */
    public function balance(string $on): ?int;
}
