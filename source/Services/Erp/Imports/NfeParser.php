<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use InvalidArgumentException;

/**
 * NF-e XML (AP-141, ADR 0086), leitura segura e sem serviço externo (sem SEFAZ):
 * - até MAX_BYTES; DOCTYPE e ENTITY recusados (sem XXE nem expansão de entidades); LIBXML_NONET;
 * - lê a chave de acesso (Id="NFe" + 44 dígitos, com o dígito verificador conferido), emitente,
 *   destinatário, número, série, emissão, valor total e as duplicatas (cobr/dup).
 * Não confere a assinatura digital (exigiria a cadeia de certificados); a NF-e só vira conta a pagar
 * depois da prévia e da aplicação. Regra pura: não grava.
 */
final class NfeParser
{
    public const MAX_BYTES = 1_000_000;
    private const NS = 'http://www.portalfiscal.inf.br/nfe';

    /** Dígito verificador da chave de acesso (regra pura, módulo 11 com pesos 2 a 9). */
    public static function validKey(string $key): bool
    {
        if (!preg_match('/^\d{44}$/', $key)) {
            return false;
        }
        $sum = 0;
        $weight = 2;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (int)$key[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }
        $rest = $sum % 11;
        $digit = $rest < 2 ? 0 : 11 - $rest;
        return (int)$key[43] === $digit;
    }

    /**
     * @return array{access_key:string, number:string, series:string, issued_on:string, issuer_document:string, issuer_name:string,
     *   recipient_document:string, total_cents:int, net_cents:?int, installments:list<array{number:string, due_on:string, amount_cents:int}>}
     */
    public static function parse(string $content): array
    {
        if ($content === '' || strlen($content) > self::MAX_BYTES) {
            throw new InvalidArgumentException('A NF-e precisa ter até 1 MB.');
        }
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            throw new InvalidArgumentException('XML com DOCTYPE ou ENTITY não é aceito.');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new InvalidArgumentException('XML inválido.');
        }
        $xml->registerXPathNamespace('n', self::NS);
        $inf = $xml->xpath('//n:infNFe')[0] ?? null;
        if ($inf === null) {
            throw new InvalidArgumentException('O arquivo não é uma NF-e (infNFe).');
        }
        $inf->registerXPathNamespace('n', self::NS);
        $one = static function (string $path) use ($inf): string {
            $node = $inf->xpath($path)[0] ?? null;
            return $node === null ? '' : trim((string)$node);
        };
        $key = (string)preg_replace('/^NFe/', '', (string)$inf['Id']);
        if (!self::validKey($key)) {
            throw new InvalidArgumentException('Chave de acesso da NF-e inválida.');
        }
        $issued = substr($one('n:ide/n:dhEmi') ?: $one('n:ide/n:dEmi'), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issued) || !checkdate((int)substr($issued, 5, 2), (int)substr($issued, 8, 2), (int)substr($issued, 0, 4))) {
            throw new InvalidArgumentException('Data de emissão da NF-e inválida.');
        }
        $total = OfxParser::amount($one('n:total/n:ICMSTot/n:vNF'));
        if ($total === null || $total <= 0) {
            throw new InvalidArgumentException('Valor total da NF-e inválido.');
        }
        $installments = [];
        foreach ($inf->xpath('n:cobr/n:dup') ?: [] as $dup) {
            $dup->registerXPathNamespace('n', self::NS);
            $due = trim((string)($dup->xpath('n:dVenc')[0] ?? ''));
            $amount = OfxParser::amount(trim((string)($dup->xpath('n:vDup')[0] ?? '')));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $amount === null || $amount <= 0) {
                throw new InvalidArgumentException('Duplicata da NF-e com vencimento ou valor inválido.');
            }
            $installments[] = ['number' => mb_substr(trim((string)($dup->xpath('n:nDup')[0] ?? '')), 0, 20), 'due_on' => $due, 'amount_cents' => $amount];
            if (count($installments) > 120) {
                throw new InvalidArgumentException('NF-e com mais de 120 duplicatas.');
            }
        }
        return [
            'access_key' => $key, 'number' => mb_substr($one('n:ide/n:nNF'), 0, 9), 'series' => mb_substr($one('n:ide/n:serie'), 0, 3), 'issued_on' => $issued,
            'issuer_document' => (string)preg_replace('/\D/', '', $one('n:emit/n:CNPJ') ?: $one('n:emit/n:CPF')),
            'issuer_name' => mb_substr($one('n:emit/n:xNome'), 0, 160),
            'recipient_document' => (string)preg_replace('/\D/', '', $one('n:dest/n:CNPJ') ?: $one('n:dest/n:CPF')),
            'total_cents' => $total, 'net_cents' => OfxParser::amount($one('n:cobr/n:fat/n:vLiq')), 'installments' => $installments,
        ];
    }
}
