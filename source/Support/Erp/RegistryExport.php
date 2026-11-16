<?php

declare(strict_types=1);

namespace Source\Support\Erp;

use Source\Services\Erp\CollectionReports;

/**
 * Tabela de download das listas dos cadastros (revisão de 15/11 item 18, ADR 0314): as mesmas colunas da tela
 * (coluna principal, colunas da lista, proprietário e inquilino nas unidades e a situação), com o texto da tela.
 * Valor em dinheiro sai como 1234,56 (a planilha soma); cada célula passa pelo csvCell (nada vira fórmula).
 */
final class RegistryExport
{
    /** Linhas no download (as telas paginam; o download junta as páginas até aqui). */
    public const MAX_ROWS = 10000;

    /**
     * @param list<array<string, mixed>> $rows linhas do RegistryService::list
     * @return array{header:list<string>, rows:list<list<string>>}
     */
    public static function table(array $definition, array $rows): array
    {
        $fields = [$definition['title'] => $definition['fields'][$definition['title']]]
            + array_filter($definition['fields'], static fn (array $f): bool => !empty($f['list']));
        $people = [];
        if (isset($definition['people']) && $rows !== [] && array_key_exists(reset($definition['people']['roles']), $rows[0])) {
            foreach ($definition['people']['roles'] as $role => $column) {
                $people[$column] = $definition['people']['labels'][$role];
            }
        }
        $header = array_merge(array_map(static fn (array $f): string => (string)($f['list_label'] ?? $f['label']), array_values($fields)), array_values($people), ['Situação']);
        $out = [];
        foreach ($rows as $row) {
            $line = [];
            foreach ($fields as $name => $field) {
                $value = $row[$name] ?? null;
                $line[] = $field['type'] === 'money' && $value !== null && $value !== ''
                    ? number_format(((int)$value) / 100, 2, ',', '')
                    : RegistryFormat::display($field, $value, $row['condominium_name'] ?? null);
            }
            foreach (array_keys($people) as $column) {
                $line[] = implode(', ', (array)($row[$column] ?? []));
            }
            $line[] = (string)($definition['status'][$row['status'] ?? ''] ?? ($row['status'] ?? ''));
            $out[] = array_map(static fn (string $cell): string => CollectionReports::csvCell($cell === '—' ? '' : $cell), $line);
        }
        return ['header' => $header, 'rows' => $out];
    }
}
