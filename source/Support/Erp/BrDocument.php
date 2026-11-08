<?php

declare(strict_types=1);

namespace Source\Support\Erp;

/**
 * Validação estrita de CPF e CNPJ (dígitos verificadores) para os cadastros do ERP.
 *
 * Não substitui o helper legado is_document(), que ainda aceita CNPJ com DV errado
 * (pendência registrada na thread "Avançar o Connect ERP"); os cadastros novos usam
 * apenas esta classe.
 */
final class BrDocument
{
    public static function digits(string $value): string
    {
        return (string)preg_replace('/\D+/', '', $value);
    }

    public static function isCpf(string $value): bool
    {
        $cpf = self::digits($value);
        if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }
        for ($t = 9; $t < 11; $t++) {
            $sum = 0;
            for ($i = 0; $i < $t; $i++) {
                $sum += (int)$cpf[$i] * ($t + 1 - $i);
            }
            if ((int)$cpf[$t] !== ((10 * $sum) % 11) % 10) {
                return false;
            }
        }
        return true;
    }

    public static function isCnpj(string $value): bool
    {
        $cnpj = self::digits($value);
        if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }
        foreach ([12, 13] as $length) {
            $weights = $length === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
            $sum = 0;
            for ($i = 0; $i < $length; $i++) {
                $sum += (int)$cnpj[$i] * $weights[$i];
            }
            $rest = $sum % 11;
            if ((int)$cnpj[$length] !== ($rest < 2 ? 0 : 11 - $rest)) {
                return false;
            }
        }
        return true;
    }

    public static function format(?string $value): string
    {
        $digits = self::digits((string)$value);
        return match (strlen($digits)) {
            11 => substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.' . substr($digits, 6, 3) . '-' . substr($digits, 9),
            14 => substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.' . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12),
            default => (string)$value,
        };
    }
}
