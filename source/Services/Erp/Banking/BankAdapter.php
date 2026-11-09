<?php

declare(strict_types=1);

namespace Source\Services\Erp\Banking;

/**
 * Adaptador bancário (docs/banking/BANKING_INTEGRATIONS.md, ADR 0035). Cada banco implementa só as
 * capacidades comprovadas na homologação. Credenciais nunca passam por aqui em texto aberto: um
 * adaptador real lê do cofre pelo identificador da conta, não do .env commitado.
 *
 * Resultado de registerCharge/getCharge:
 *   ['status' => 'registered'|'pending'|'rejected', 'external_id' => ?string,
 *    'digitable_line' => ?string, 'barcode' => ?string, 'error' => ?string]
 * 'pending' = sem confirmação do banco (timeout, fila): o título fica verificável por getCharge.
 *
 * Evento de parseReturn: ['event_key' => string, 'kind' => 'registered'|'rejected'|'paid'|'cancelled',
 *   'our_number' => string, 'amount_cents' => ?int, 'occurred_on' => ?string (AAAA-MM-DD)]
 */
interface BankAdapter
{
    /** Nome do adaptador gravado no boleto (ex.: 'sandbox'). */
    public function name(): string;

    /** @return list<string> capacidades: register, query, cancel, return_file */
    public function capabilities(): array;

    /**
     * @param array{our_number:string, amount_cents:int, due_on:string, payer_name:?string, description:string,
     *   bank_code:string, branch:string, account_number:string, wallet:?string} $charge
     */
    public function registerCharge(array $charge): array;

    public function getCharge(string $ourNumber, ?string $externalId): array;

    /** @return array{status:'cancelled'|'pending'|'rejected', error:?string} */
    public function cancelCharge(string $ourNumber, ?string $externalId): array;

    /**
     * Lê um arquivo de retorno do layout deste adaptador. Layout desconhecido, trailer ou totais
     * que não batem = InvalidArgumentException (o arquivo inteiro é recusado).
     *
     * @return list<array{event_key:string, kind:string, our_number:string, amount_cents:?int, occurred_on:?string}>
     */
    public function parseReturn(string $content): array;
}
