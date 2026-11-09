<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use InvalidArgumentException;

/**
 * Extrato bancário OFX (AP-140, ADR 0058), versões 1 (SGML, tags sem fechamento) e 2 (XML). Lê a
 * conta (BANKID, ACCTID), as transações (STMTTRN: tipo, data, valor, FITID, histórico) e o saldo
 * (LEDGERBAL). Regra pura: não grava. Sem DTD nem entidades (lido por expressão, não por parser XML).
 */
final class OfxParser
{
    public const MAX_TRANSACTIONS = 5000;

    /**
     * @return array{bank_id:string, account_id:string, from:?string, to:?string, ledger_balance_cents:?int, ledger_as_of:?string,
     *   transactions:list<array{fitid:string, kind:string, posted_on:string, amount_cents:int, memo:string}>}
     */
    public static function parse(string $content): array
    {
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        if (stripos($content, '<OFX>') === false) {
            throw new InvalidArgumentException('O arquivo não é um extrato OFX.');
        }
        $body = substr($content, (int)stripos($content, '<OFX>'));
        $account = self::tag($body, 'ACCTID');
        if ($account === null || $account === '') {
            throw new InvalidArgumentException('O OFX não informa a conta (ACCTID).');
        }
        if (!preg_match_all('/<STMTTRN>(.*?)(?=<\/STMTTRN>|<STMTTRN>|<\/BANKTRANLIST>)/is', $body, $blocks)) {
            throw new InvalidArgumentException('O OFX não tem transações (STMTTRN).');
        }
        if (count($blocks[1]) > self::MAX_TRANSACTIONS) {
            throw new InvalidArgumentException(sprintf('O OFX passa de %d transações.', self::MAX_TRANSACTIONS));
        }
        $transactions = [];
        $seen = [];
        foreach ($blocks[1] as $index => $block) {
            $number = $index + 1;
            $fitid = self::tag($block, 'FITID');
            $date = self::date(self::tag($block, 'DTPOSTED'));
            $amount = self::amount(self::tag($block, 'TRNAMT'));
            if ($fitid === null || $fitid === '' || $date === null || $amount === null) {
                throw new InvalidArgumentException("Transação {$number} do OFX sem FITID, data ou valor válidos.");
            }
            if (isset($seen[$fitid])) {
                throw new InvalidArgumentException("FITID repetido no arquivo: {$fitid}.");
            }
            $seen[$fitid] = true;
            $memo = trim((string)(self::tag($block, 'MEMO') ?? self::tag($block, 'NAME') ?? ''));
            $transactions[] = ['fitid' => mb_substr($fitid, 0, 80), 'kind' => $amount >= 0 ? 'credit' : 'debit', 'posted_on' => $date,
                'amount_cents' => $amount, 'memo' => mb_substr($memo, 0, 160)];
        }
        $ledger = null;
        $ledgerOn = null;
        if (preg_match('/<LEDGERBAL>(.*?)(?=<\/LEDGERBAL>|<AVAILBAL>|<\/STMTRS>)/is', $body, $match)) {
            $ledger = self::amount(self::tag($match[1], 'BALAMT'));
            $ledgerOn = self::date(self::tag($match[1], 'DTASOF'));
        }
        return [
            'bank_id' => (string)(self::tag($body, 'BANKID') ?? ''), 'account_id' => $account,
            'from' => self::date(self::tag($body, 'DTSTART')), 'to' => self::date(self::tag($body, 'DTEND')),
            'ledger_balance_cents' => $ledger, 'ledger_as_of' => $ledgerOn, 'transactions' => $transactions,
        ];
    }

    /** A conta do OFX é a conta bancária cadastrada? Compara só os dígitos, sem zeros à esquerda (regra pura). */
    public static function sameAccount(string $ofxAccount, string $registered): bool
    {
        $a = ltrim((string)preg_replace('/\D/', '', $ofxAccount), '0');
        $b = ltrim((string)preg_replace('/\D/', '', $registered), '0');
        return $a !== '' && $a === $b;
    }

    /** "20261105120000[-3:BRT]" → "2026-11-05" (regra pura). */
    public static function date(?string $value): ?string
    {
        if ($value === null || !preg_match('/^(\d{4})(\d{2})(\d{2})/', trim($value), $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
            return null;
        }
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** "-1234.56" ou "1234,56" → centavos com sinal (regra pura). */
    public static function amount(?string $value): ?int
    {
        $value = trim((string)$value);
        if (!preg_match('/^([+-]?)(\d+)(?:[.,](\d{1,2}))?$/', $value, $m)) {
            return null;
        }
        $cents = (int)$m[2] * 100 + (int)str_pad($m[3] ?? '0', 2, '0');
        return $m[1] === '-' ? -$cents : $cents;
    }

    private static function tag(string $content, string $name): ?string
    {
        if (!preg_match('/<' . $name . '>([^<\r\n]*)/i', $content, $match)) {
            return null;
        }
        return trim(html_entity_decode($match[1], ENT_QUOTES | ENT_XML1, 'UTF-8'));
    }
}
