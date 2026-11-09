<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

/**
 * Regras puras das importações (ADR 0058): leitura dos valores das células e a conciliação do
 * extrato. Nada aqui consulta o banco.
 */
final class ImportRules
{
    /** Tolerância de datas entre o extrato e o lançamento pago (dias). */
    public const MATCH_DAYS = 3;

    /**
     * Valor em centavos: "1.234,56", "1234,56", "1234.56" (XLSX grava ponto) ou "R$ 10". Negativo,
     * zero ou ilegível = null.
     */
    public static function money(string $value): ?int
    {
        $value = trim(str_ireplace(['R$', ' '], '', $value));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d{1,3}(\.\d{3})+(,\d{1,2})?$|^\d+,\d{1,2}$/', $value)) {
            $value = str_replace(['.', ','], ['', '.'], $value);
        } elseif (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        $cents = (int)round(((float)$value) * 100);
        return $cents > 0 ? $cents : null;
    }

    /** Data: DD/MM/AAAA, AAAA-MM-DD ou número de série do Excel (dias desde 1899-12-30). */
    public static function date(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) {
            [$y, $mo, $d] = [(int)$m[3], (int)$m[2], (int)$m[1]];
        } elseif (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^\d{5}(\.0+)?$/', $value)) {
            $serial = (int)$value;
            if ($serial < 20000 || $serial > 80000) {
                return null;
            }
            return (new \DateTimeImmutable('1899-12-30'))->modify("+{$serial} days")->format('Y-m-d');
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /** Competência: MM/AAAA ou AAAA-MM (ou uma data, de que se tira o mês). */
    public static function competence(string $value): ?string
    {
        $value = trim($value);
        if (preg_match('/^(0[1-9]|1[0-2])\/(\d{4})$/', $value, $m)) {
            return "{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value)) {
            return $value;
        }
        $date = self::date($value);
        return $date !== null ? substr($date, 0, 7) : null;
    }

    /** Fração ideal: "0,012500", "0.0125" ou "1,25%" → texto com 6 casas, entre 0 (exclusive) e 1. */
    public static function fraction(string $value): ?string
    {
        $value = str_replace(',', '.', trim($value));
        $percent = str_ends_with($value, '%');
        $value = rtrim($value, '%');
        if (!preg_match('/^\d+(\.\d+)?$/', $value)) {
            return null;
        }
        $number = (float)$value / ($percent ? 100 : 1);
        return $number > 0 && $number <= 1 ? number_format($number, 6, '.', '') : null;
    }

    /** Natureza: receita/r/crédito ou despesa/d/débito. */
    public static function nature(string $value): ?string
    {
        $value = mb_strtolower(trim($value));
        return match (true) {
            in_array($value, ['receita', 'r', 'credito', 'crédito', 'entrada'], true) => 'receita',
            in_array($value, ['despesa', 'd', 'debito', 'débito', 'saida', 'saída'], true) => 'despesa',
            default => null,
        };
    }

    /** Tipo do relógio: água, gás ou energia (com ou sem acento, maiúsculas). */
    public static function meterKind(string $value): ?string
    {
        $value = SpreadsheetReader::normalizeHeader($value);
        return match ($value) {
            'agua' => 'agua', 'gas' => 'gas', 'energia', 'luz' => 'energia',
            default => null,
        };
    }

    /**
     * Conferência do saldo do extrato (regra pura): diferença = saldo do banco − saldo calculado.
     * Só informa; nada é ajustado. Sem saldo no extrato, não há conferência.
     *
     * @return array{bank_cents:?int, system_cents:?int, difference_cents:?int, matches:?bool}
     */
    public static function balanceCheck(?int $bankCents, ?int $systemCents): array
    {
        if ($bankCents === null || $systemCents === null) {
            return ['bank_cents' => $bankCents, 'system_cents' => $systemCents, 'difference_cents' => null, 'matches' => null];
        }
        $difference = $bankCents - $systemCents;
        return ['bank_cents' => $bankCents, 'system_cents' => $systemCents, 'difference_cents' => $difference, 'matches' => $difference === 0];
    }

    /**
     * Conciliação do extrato (regra pura). Cada linha procura candidatos do mesmo sentido (crédito =
     * entrada, débito = saída), mesmo valor e até MATCH_DAYS dias de diferença, ainda não usados.
     * Só confere com exatamente um candidato; nenhum ou mais de um = pendente (nunca escolhe sozinha).
     *
     * @param list<array{fitid:string, posted_on:string, amount_cents:int}> $lines
     * @param list<array{key:string, direction:string, on:string, amount_cents:int}> $candidates direction in|out
     * @return array<string, ?string> fitid => key do candidato (ou null)
     */
    public static function match(array $lines, array $candidates): array
    {
        $used = [];
        $out = [];
        foreach ($lines as $line) {
            $direction = $line['amount_cents'] >= 0 ? 'in' : 'out';
            $amount = abs($line['amount_cents']);
            $posted = new \DateTimeImmutable($line['posted_on']);
            $found = [];
            foreach ($candidates as $candidate) {
                if (isset($used[$candidate['key']]) || $candidate['direction'] !== $direction || (int)$candidate['amount_cents'] !== $amount) {
                    continue;
                }
                $days = (int)$posted->diff(new \DateTimeImmutable($candidate['on']))->format('%a');
                if ($days <= self::MATCH_DAYS) {
                    $found[] = $candidate['key'];
                }
            }
            $out[$line['fitid']] = count($found) === 1 ? $found[0] : null;
            if (count($found) === 1) {
                $used[$found[0]] = true;
            }
        }
        return $out;
    }
}
