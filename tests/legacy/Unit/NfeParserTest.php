<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Source\Services\Erp\Imports\NfeParser;

/** NF-e XML (ADR 0086): chave com dígito verificador, leitura sem DOCTYPE nem entidades, duplicatas. */
final class NfeParserTest extends TestCase
{
    /** Chave de 44 dígitos com o dígito verificador correto, a partir de 43 dígitos. */
    public static function key(string $first43): string
    {
        $sum = 0;
        $weight = 2;
        for ($i = 42; $i >= 0; $i--) {
            $sum += (int)$first43[$i] * $weight;
            $weight = $weight === 9 ? 2 : $weight + 1;
        }
        $rest = $sum % 11;
        return $first43 . ($rest < 2 ? 0 : 11 - $rest);
    }

    public static function xml(string $key, string $dups = '', string $vNF = '1500.00', string $extra = ''): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>' . $extra . '<nfeProc xmlns="http://www.portalfiscal.inf.br/nfe" versao="4.00"><NFe><infNFe Id="NFe' . $key . '" versao="4.00">'
            . '<ide><serie>1</serie><nNF>123</nNF><dhEmi>2026-11-05T10:00:00-03:00</dhEmi></ide>'
            . '<emit><CNPJ>11.222.333/0001-81</CNPJ><xNome>Limpeza &amp; Cia</xNome></emit><dest><CNPJ>44555666000199</CNPJ></dest>'
            . '<total><ICMSTot><vNF>' . $vNF . '</vNF></ICMSTot></total>' . $dups . '</infNFe></NFe></nfeProc>';
    }

    public function testKeyCheckDigit(): void
    {
        $key = self::key(str_repeat('3', 2) . '2611' . '11222333000181' . '55' . '001' . '000000123' . '1' . '00000001');
        self::assertSame(44, strlen($key));
        self::assertTrue(NfeParser::validKey($key));
        self::assertFalse(NfeParser::validKey(substr($key, 0, 43) . (((int)$key[43] + 1) % 10)), 'Dígito trocado.');
        self::assertFalse(NfeParser::validKey('123'));
    }

    public function testParseWithInstallments(): void
    {
        $key = self::key(str_repeat('1', 43));
        $dups = '<cobr><fat><nFat>123</nFat><vLiq>1500.00</vLiq></fat><dup><nDup>001</nDup><dVenc>2026-12-05</dVenc><vDup>750.00</vDup></dup>'
            . '<dup><nDup>002</nDup><dVenc>2027-01-05</dVenc><vDup>750.00</vDup></dup></cobr>';
        $nfe = NfeParser::parse(self::xml($key, $dups));
        self::assertSame([$key, '123', '1', '2026-11-05', '11222333000181', 'Limpeza & Cia', '44555666000199', 150000, 150000],
            [$nfe['access_key'], $nfe['number'], $nfe['series'], $nfe['issued_on'], $nfe['issuer_document'], $nfe['issuer_name'], $nfe['recipient_document'], $nfe['total_cents'], $nfe['net_cents']]);
        self::assertSame([['number' => '001', 'due_on' => '2026-12-05', 'amount_cents' => 75000], ['number' => '002', 'due_on' => '2027-01-05', 'amount_cents' => 75000]], $nfe['installments']);
        self::assertSame([], NfeParser::parse(self::xml($key))['installments'], 'Sem cobr, sem duplicatas.');
    }

    public function testUnsafeOrInvalidXmlIsRefused(): void
    {
        $key = self::key(str_repeat('1', 43));
        $cases = [
            'DOCTYPE/XXE' => self::xml($key, '', '1500.00', '<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'),
            'chave errada' => self::xml(substr($key, 0, 43) . (((int)$key[43] + 1) % 10)),
            'sem infNFe' => '<?xml version="1.0"?><outro/>',
            'XML quebrado' => '<nfeProc><NFe>',
            'valor zero' => self::xml($key, '', '0.00'),
            'grande demais' => str_repeat(' ', NfeParser::MAX_BYTES + 1),
        ];
        foreach ($cases as $label => $content) {
            try {
                NfeParser::parse($content);
                self::fail("Aceitou: {$label}");
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
