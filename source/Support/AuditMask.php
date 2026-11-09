<?php

declare(strict_types=1);

namespace Source\Support;

/**
 * Máscara de dados pessoais na auditoria (ADR 0087, A1). Regras puras.
 *
 * - fields(): no motor de cadastros, troca o valor dos campos pessoais (documento, e-mail, telefone)
 *   pela forma mascarada; a auditoria registra que mudou, sem o valor em claro.
 * - text(): no relatório de logs (AP-097), mascara no texto do contexto qualquer CPF, CNPJ, e-mail ou
 *   telefone que tenha ficado em registros antigos.
 */
final class AuditMask
{
    /** Tipos de campo do motor (Definitions) que são dado pessoal. */
    public const PERSONAL_TYPES = ['document', 'cpf', 'cnpj', 'id_document', 'email', 'phone'];

    /**
     * @param array<string, mixed> $values coluna => valor
     * @param array<string, array> $fields definição dos campos (Definitions)
     * @return array<string, mixed>
     */
    public static function fields(array $values, array $fields): array
    {
        foreach ($values as $column => $value) {
            $type = $fields[$column]['type'] ?? null;
            if ($value === null || $value === '' || !in_array($type, self::PERSONAL_TYPES, true)) {
                continue;
            }
            $values[$column] = $type === 'email' ? self::email((string)$value) : self::digits((string)$value, $type === 'phone' ? 4 : 2);
        }
        return $values;
    }

    public static function text(string $text): string
    {
        $text = (string)preg_replace_callback('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', static fn (array $m): string => self::email($m[0]), $text);
        // CPF e CNPJ formatados, telefone formatado e sequências de 10 a 14 dígitos (documento ou telefone
        // sem pontuação). Datas (AAAA-MM-DD), horas e valores em centavos não casam com nenhum dos formatos.
        $patterns = [
            '/(?<![\d.])\d{3}\.\d{3}\.\d{3}-\d{2}(?!\d)/' => 2,
            '/(?<![\d.])\d{2}\.\d{3}\.\d{3}\/\d{4}-\d{2}(?!\d)/' => 2,
            '/\(\d{2}\)\s?\d{4,5}-\d{4}(?!\d)/' => 4,
            '/(?<![\d.\-])\d{10,14}(?![\d.\-])/' => 2,
        ];
        foreach ($patterns as $pattern => $keep) {
            $text = (string)preg_replace_callback($pattern, static fn (array $m): string => self::digits($m[0], $keep), $text);
        }
        return $text;
    }

    private static function email(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($local, 0, 1) . str_repeat('*', max(1, min(6, mb_strlen($local) - 1))) . '@' . $domain;
    }

    private static function digits(string $value, int $keep): string
    {
        $digits = (string)preg_replace('/\D/', '', $value);
        return $digits === '' ? '' : str_repeat('*', max(0, strlen($digits) - $keep)) . substr($digits, -$keep);
    }
}
