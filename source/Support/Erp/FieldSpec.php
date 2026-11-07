<?php

declare(strict_types=1);

namespace Source\Support\Erp;

/**
 * Interpreta a notação "Rótulo:tipo(opção|opção)!@N" de components/layout/page-specs.php.
 * "!" marca obrigatório e "@N" a largura em 12 colunas.
 */
final class FieldSpec
{
    /** @return array{label: string, type: string, options: list<string>, required: bool, span: int, id: string, numeric: bool} */
    public static function parse(string $spec, string $prefix = 'f'): array
    {
        $span = 6;
        if (preg_match('/@(\d+)$/', $spec, $m)) {
            $span = max(1, min(12, (int)$m[1]));
            $spec = substr($spec, 0, -strlen($m[0]));
        }
        $required = str_ends_with($spec, '!');
        $spec = rtrim($spec, '!');
        [$label, $type] = array_pad(explode(':', $spec, 2), 2, 'text');
        $options = [];
        if (preg_match('/^([a-z]+)\((.*)\)$/', $type, $m)) {
            $type = $m[1];
            $options = explode('|', $m[2]);
        }
        return [
            'label' => $label,
            'type' => $type,
            'options' => $options,
            'required' => $required,
            'span' => $span,
            'id' => $prefix . '-' . self::slug($label),
            'numeric' => in_array($type, ['money', 'int', 'pct', 'date', 'month'], true),
        ];
    }

    /** @param list<string> $specs @return list<array<string, mixed>> */
    public static function parseAll(array $specs, string $prefix = 'f'): array
    {
        return array_map(static fn (string $s): array => self::parse($s, $prefix), $specs);
    }

    public static function slug(string $text): string
    {
        $ascii = strtr(mb_strtolower($text), ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c', 'º' => 'o', 'ª' => 'a', '²' => '2', '³' => '3']);
        return trim((string)preg_replace('/[^a-z0-9]+/', '-', $ascii), '-');
    }
}
