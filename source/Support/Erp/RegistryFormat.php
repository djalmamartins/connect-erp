<?php

declare(strict_types=1);

namespace Source\Support\Erp;

/** Formatação de valores dos cadastros para exibição (listagem, detalhe e formulário). */
final class RegistryFormat
{
    public static function phone(?string $digits): string
    {
        $d = BrDocument::digits((string)$digits);
        if (str_starts_with($d, '55') && strlen($d) >= 12) {
            $d = substr($d, 2);
        }
        return match (strlen($d)) {
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            default => (string)$digits,
        };
    }

    /** Texto de exibição de um campo já normalizado pelo serviço. */
    public static function display(array $field, mixed $value, ?string $condominiumName = null): string
    {
        if ($value === null || $value === '') {
            return $field['type'] === 'condominium' ? ($condominiumName ?? 'Todos') : '—';
        }
        return match ($field['type']) {
            'condominium' => (string)($condominiumName ?? $value),
            'select' => (string)($field['options'][$value] ?? $value),
            'bool' => $value ? 'Sim' : 'Não',
            'cpf', 'cnpj', 'document' => BrDocument::format((string)$value),
            'id_document' => strlen((string)$value) === 11 && ctype_digit((string)$value) ? BrDocument::format((string)$value) : (string)$value,
            'phone' => self::phone((string)$value),
            'date' => ($d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value)) ? $d->format('d/m/Y') : (string)$value,
            'money' => 'R$ ' . number_format(((int)$value) / 100, 2, ',', '.'),
            'int' => number_format((int)$value, 0, ',', '.'),
            'time' => substr((string)$value, 0, 5),
            'percent' => number_format((float)$value, 2, ',', '.') . '%',
            'plate' => strlen((string)$value) === 7 ? substr((string)$value, 0, 3) . '-' . substr((string)$value, 3) : (string)$value,
            default => (string)$value,
        };
    }
}
