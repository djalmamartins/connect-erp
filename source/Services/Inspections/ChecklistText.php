<?php

declare(strict_types=1);

namespace Source\Services\Inspections;

/**
 * Formato de texto dos itens de um modelo de checklist, para editar numa caixa de texto
 * no celular ou no computador:
 *
 *   # Portaria                      ← começa uma seção
 *   Portões e interfone funcionando ← um item
 *   Extintores na validade [foto]   ← item que exige foto
 */
final class ChecklistText
{
    public const MAX_ITEMS = 120;
    private const PHOTO_MARK = '/\s*\[foto\]\s*$/iu';

    /**
     * @return list<array{position: int, section: string, label: string, requires_photo: bool}>
     * @throws InspectionError quando não há itens, há itens demais ou textos longos demais
     */
    public static function parse(string $text): array
    {
        $section = 'Geral';
        $items = [];
        foreach (preg_split('/\R/u', $text) ?: [] as $line) {
            $line = trim((string)preg_replace('/\s+/u', ' ', $line));
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '#')) {
                $section = trim(ltrim($line, '#')) ?: 'Geral';
                if (mb_strlen($section) > 80) {
                    throw InspectionError::invalid(['items' => 'Nome de seção com mais de 80 caracteres: ' . mb_substr($section, 0, 40) . '…']);
                }
                continue;
            }
            $requiresPhoto = (bool)preg_match(self::PHOTO_MARK, $line);
            $label = trim((string)preg_replace(self::PHOTO_MARK, '', $line));
            $label = ltrim($label, "-*• \t");
            if ($label === '') {
                continue;
            }
            if (mb_strlen($label) > 200) {
                throw InspectionError::invalid(['items' => 'Item com mais de 200 caracteres: ' . mb_substr($label, 0, 40) . '…']);
            }
            $items[] = ['position' => count($items) + 1, 'section' => $section, 'label' => $label, 'requires_photo' => $requiresPhoto];
        }
        if ($items === []) {
            throw InspectionError::invalid(['items' => 'Informe pelo menos um item do checklist.']);
        }
        if (count($items) > self::MAX_ITEMS) {
            throw InspectionError::invalid(['items' => 'Use no máximo ' . self::MAX_ITEMS . ' itens por modelo.']);
        }
        return $items;
    }

    /** @param list<array{section: string, label: string, requires_photo: bool|int}> $items */
    public static function format(array $items): string
    {
        $lines = [];
        $section = null;
        foreach ($items as $item) {
            if ($item['section'] !== $section) {
                $section = $item['section'];
                if ($lines !== []) {
                    $lines[] = '';
                }
                $lines[] = '# ' . $section;
            }
            $lines[] = $item['label'] . ((bool)$item['requires_photo'] ? ' [foto]' : '');
        }
        return implode("\n", $lines);
    }
}
