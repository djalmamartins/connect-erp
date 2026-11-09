<?php

declare(strict_types=1);

namespace Source\Services\Erp\Imports;

use InvalidArgumentException;
use ZipArchive;

/**
 * Leitura de planilha para as importações (ADR 0058), sem biblioteca externa: CSV (separador ; ou ,
 * detectado pelo cabeçalho, UTF-8 com ou sem BOM, ou ISO-8859-1) e XLSX (primeira aba, pelo
 * ZipArchive e SimpleXML; fórmulas entram pelo valor calculado gravado no arquivo).
 *
 * A primeira linha é o cabeçalho. Os nomes das colunas são normalizados (minúsculas, sem acento,
 * espaço vira _). Linhas totalmente vazias são ignoradas. Nada aqui grava: só lê.
 */
final class SpreadsheetReader
{
    public const MAX_ROWS = 5000;
    public const MAX_UNCOMPRESSED = 30_000_000;
    /** Colunas por linha e posição da última linha no XLSX: "XFD1048576" numa célula só não vira milhões de células vazias (ADR 0063). */
    public const MAX_COLUMNS = 200;
    public const MAX_ROW_INDEX = 20_000;

    /** Nome de coluna normalizado (regra pura): "Competência" → "competencia", "Pago em" → "pago_em". */
    public static function normalizeHeader(string $name): string
    {
        $name = mb_strtolower(trim($name));
        $name = strtr($name, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
        return trim((string)preg_replace('/[^a-z0-9]+/', '_', $name), '_');
    }

    /**
     * @return array{header:list<string>, rows:list<array{line:int, values:array<string, string>}>}
     */
    public static function read(string $content, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $matrix = match ($extension) {
            'csv', 'txt' => self::csv($content),
            'xlsx' => self::xlsx($content),
            default => throw new InvalidArgumentException('Formato não aceito. Envie CSV ou XLSX.'),
        };
        if ($matrix === []) {
            throw new InvalidArgumentException('A planilha está vazia.');
        }
        $header = array_map(static fn ($h): string => self::normalizeHeader((string)$h), array_shift($matrix));
        if (count(array_filter($header)) === 0) {
            throw new InvalidArgumentException('A primeira linha precisa ter o nome das colunas.');
        }
        if (count(array_filter($header)) !== count(array_unique(array_filter($header)))) {
            throw new InvalidArgumentException('Há colunas com o mesmo nome no cabeçalho.');
        }
        $rows = [];
        foreach ($matrix as $index => $cells) {
            $values = [];
            foreach ($header as $position => $name) {
                if ($name !== '') {
                    $values[$name] = trim((string)($cells[$position] ?? ''));
                }
            }
            if (implode('', $values) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new InvalidArgumentException(sprintf('A planilha passa de %d linhas. Divida o arquivo.', self::MAX_ROWS));
            }
            $rows[] = ['line' => $index + 2, 'values' => $values];
        }
        if ($rows === []) {
            throw new InvalidArgumentException('A planilha não tem linhas de dados.');
        }
        return ['header' => array_values(array_filter($header)), 'rows' => $rows];
    }

    /** @return list<list<string>> */
    public static function csv(string $content): array
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        } elseif (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'ISO-8859-1');
        }
        $content = str_replace(["\r\n", "\r"], "\n", $content);
        $first = strtok($content, "\n") ?: '';
        $delimiter = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $matrix = [];
        while (($cells = fgetcsv($handle, null, $delimiter, '"', '')) !== false) {
            if ($cells === [null]) {
                $matrix[] = [];
                continue;
            }
            $matrix[] = array_map(static fn ($c): string => (string)$c, $cells);
            if (count($matrix) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($handle);
        while ($matrix !== [] && end($matrix) === []) {
            array_pop($matrix);
        }
        return $matrix;
    }

    /** @return list<list<string>> */
    public static function xlsx(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        file_put_contents($path, $content);
        $zip = new ZipArchive();
        try {
            if ($zip->open($path) !== true) {
                throw new InvalidArgumentException('Arquivo XLSX inválido.');
            }
            // Defesa contra "zip bomb": o conteúdo descompactado tem limite.
            foreach (['xl/sharedStrings.xml', 'xl/worksheets/sheet1.xml'] as $entry) {
                $stat = $zip->statName($entry);
                if ($stat !== false && (int)$stat['size'] > self::MAX_UNCOMPRESSED) {
                    throw new InvalidArgumentException('O XLSX é grande demais depois de descompactado.');
                }
            }
            $shared = [];
            // O tamanho do diretório do ZIP é declarado pelo arquivo; a leitura também para no limite (ADR 0063).
            $strings = $zip->getFromName('xl/sharedStrings.xml', self::MAX_UNCOMPRESSED);
            if ($strings !== false) {
                $xml = self::xml($strings);
                foreach ($xml->si as $si) {
                    $text = isset($si->t) ? (string)$si->t : '';
                    foreach ($si->r ?? [] as $run) {
                        $text .= (string)$run->t;
                    }
                    $shared[] = $text;
                }
            }
            $sheet = $zip->getFromName('xl/worksheets/sheet1.xml', self::MAX_UNCOMPRESSED);
            if ($sheet === false) {
                throw new InvalidArgumentException('O XLSX não tem a primeira aba (sheet1).');
            }
            $xml = self::xml($sheet);
            $matrix = [];
            foreach ($xml->sheetData->row ?? [] as $row) {
                $cells = [];
                foreach ($row->c as $cell) {
                    $column = self::columnIndex((string)$cell['r']);
                    if ($column >= self::MAX_COLUMNS) {
                        throw new InvalidArgumentException(sprintf('A planilha passa de %d colunas.', self::MAX_COLUMNS));
                    }
                    $type = (string)$cell['t'];
                    $value = match ($type) {
                        's' => $shared[(int)$cell->v] ?? '',
                        'inlineStr' => (string)($cell->is->t ?? ''),
                        'b' => (string)$cell->v === '1' ? 'sim' : 'não',
                        default => (string)$cell->v,
                    };
                    $cells[$column] = $value;
                }
                $rowIndex = max(1, (int)$row['r']) - 1;
                if ($rowIndex >= self::MAX_ROW_INDEX) {
                    throw new InvalidArgumentException(sprintf('A planilha tem dados depois da linha %d. Divida o arquivo.', self::MAX_ROW_INDEX));
                }
                $line = [];
                $max = $cells === [] ? -1 : max(array_keys($cells));
                for ($i = 0; $i <= $max; $i++) {
                    $line[] = $cells[$i] ?? '';
                }
                $matrix[$rowIndex] = $line;
                if (count($matrix) > self::MAX_ROWS + 1) {
                    break;
                }
            }
            if ($matrix === []) {
                return [];
            }
            $out = [];
            for ($i = 0, $last = max(array_keys($matrix)); $i <= $last; $i++) {
                $out[] = $matrix[$i] ?? [];
            }
            return $out;
        } finally {
            $zip->close();
            @unlink($path);
        }
    }

    /** "C12" → 2 (regra pura). */
    public static function columnIndex(string $reference): int
    {
        $letters = (string)preg_replace('/[^A-Z]/', '', strtoupper($reference));
        if (strlen($letters) > 3) {
            return PHP_INT_MAX;   // além de XFD (a última coluna do Excel); sem estourar o inteiro
        }
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }
        return max(0, $index - 1);
    }

    private static function xml(string $content): \SimpleXMLElement
    {
        // Sem entidades externas (XXE): o XLSX não precisa de DTD.
        if (stripos($content, '<!DOCTYPE') !== false) {
            throw new InvalidArgumentException('XLSX com DOCTYPE não é aceito.');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new InvalidArgumentException('XLSX corrompido.');
        }
        return $xml;
    }
}
