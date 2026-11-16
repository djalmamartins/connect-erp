<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use Dompdf\Dompdf;
use Dompdf\Options;
use InvalidArgumentException;
use PDO;
use Source\Core\Connect;
use ZipArchive;

/**
 * Download das listas e relatórios em PDF e Excel (revisão de 15/11 item 18, modelo 3, ADR 0314).
 *
 * Recebe a mesma tabela do CSV ({header, rows}, células já saneadas por CollectionReports::csvCell) e gera:
 * - CSV: o arquivo de sempre (CollectionReports::csv), sem mudança;
 * - PDF (dompdf, A4 deitado, sem acesso remoto): cabeçalho da Connect (nome, CNPJ e endereço), a data, o condomínio
 *   e o título no centro, a tabela com o cabeçalho repetido em cada página, e no rodapé o site e o número da página;
 * - Excel (.xlsx gerado com ZipArchive, sem biblioteca nova): as mesmas linhas de cabeçalho e a tabela; valores no
 *   formato 1234,56 viram número (somam na planilha), o resto vai como texto (texto nunca vira fórmula no xlsx).
 */
final class ListDocument
{
    public const FORMATS = ['csv' => 'text/csv; charset=utf-8', 'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    public const LABELS = ['pdf' => 'PDF', 'xlsx' => 'Excel', 'csv' => 'CSV'];
    /** Linhas no PDF (o Excel e o CSV levam tudo o que a consulta trouxe). */
    public const MAX_PDF_ROWS = 5000;
    /** Cabeçalhos que "Ocultar unidade" tira do arquivo. */
    public const UNIT_HEADERS = ['Unidade'];

    /** CONFIRMADO no modelo 3 da revisão de 15/11 (cabeçalho dos PDFs da Connect). */
    private const ISSUER = ['name' => 'CONNECT CONDOMÍNIOS LTDA', 'document' => '43.346.552/0001-69',
        'address' => 'Travessa Comendador Lalão, 10 - Sala 401 - Centro - 36400-040 - Conselheiro Lafaiete - MG',
        'site' => 'www.connectcondominios.com.br'];

    /** Formato pedido (regra pura): csv, pdf ou xlsx; null quando não é download. */
    public static function format(mixed $value): ?string
    {
        $value = is_string($value) ? strtolower(trim($value)) : '';
        $value = $value === 'excel' || $value === 'xls' ? 'xlsx' : $value;
        return isset(self::FORMATS[$value]) ? $value : null;
    }

    /** Título vindo da tela (regra pura): texto simples, até 120 caracteres; vazio usa o padrão. */
    public static function title(mixed $value, string $fallback): string
    {
        $text = is_string($value) ? trim((string)preg_replace('/[\p{Cc}<>]+/u', ' ', strip_tags($value))) : '';
        $text = (string)preg_replace('/\s+/u', ' ', $text);
        return $text === '' ? $fallback : mb_substr($text, 0, 120);
    }

    /** Nome do arquivo (regra pura): letras, números e hífen, com a data. */
    public static function filename(string $base, string $format, ?string $date = null): string
    {
        $ascii = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $base);
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $ascii), '-'));
        return substr($slug !== '' ? $slug : 'lista', 0, 80) . '-' . ($date ?? date('Y-m-d')) . '.' . $format;
    }

    /** Célula como aparece no PDF e no Excel (regra pura): tira o apóstrofo de proteção que o CSV pôs. */
    public static function plain(string $cell): string
    {
        return str_starts_with($cell, "'") && CollectionReports::csvCell(substr($cell, 1)) === $cell ? substr($cell, 1) : $cell;
    }

    /** Número da planilha (regra pura): "1234,56", "-12,5" ou "42" viram float/int; o resto null. */
    public static function number(string $cell): int|float|null
    {
        if (preg_match('/^-?\d{1,15}$/', $cell) === 1 && !preg_match('/^-?0\d/', $cell)) {
            return (int)$cell;
        }
        return preg_match('/^-?\d{1,15},\d{1,6}$/', $cell) === 1 ? (float)str_replace(',', '.', $cell) : null;
    }

    /** "NOME (Cidade / BAIRRO)" do condomínio, como no modelo; null quando é "Todos". */
    public static function condominiumLabel(?int $condominiumId, ?PDO $pdo = null): ?string
    {
        if ($condominiumId === null || $condominiumId < 1) {
            return null;
        }
        $stmt = ($pdo ?? Connect::getInstance())->prepare('SELECT name, city, district FROM condominiums WHERE id = ?');
        $stmt->execute([$condominiumId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $place = implode(' / ', array_filter([trim((string)$row['city']), trim((string)$row['district'])], static fn (string $p): bool => $p !== ''));
        return trim((string)$row['name']) . ($place !== '' ? " ({$place})" : '');
    }

    /**
     * Conteúdo do arquivo.
     * @param array{header:list<string>, rows:list<list<string>>} $table
     * @param array{title:string, condominium?:?string, date?:string} $meta
     */
    public static function render(string $format, array $table, array $meta): string
    {
        if (!empty($meta['hide_unit'])) {
            $table = self::withoutUnit($table);
        }
        return match ($format) {
            'csv' => CollectionReports::csv($table),
            'pdf' => self::pdf($table, $meta),
            'xlsx' => self::xlsx($table, $meta),
            default => throw new InvalidArgumentException('Formato de download inválido.'),
        };
    }

    /**
     * Cabeçalho do documento: título (da tela ou o padrão), condomínio ("NOME (Cidade / BAIRRO)" ou "Todos os
     * condomínios") e a data de hoje.
     * @return array{title:string, condominium:?string, date:string}
     */
    public static function meta(mixed $title, string $fallback, ?int $condominiumId): array
    {
        // ?hide_unit=1 (revisão de 15/11 item 19): o arquivo sai sem a coluna Unidade, como na tela com "Ocultar unidade".
        return ['title' => self::title($title, $fallback), 'condominium' => self::condominiumLabel($condominiumId), 'date' => date('d/m/Y'),
            'hide_unit' => ($_GET['hide_unit'] ?? '') === '1'];
    }

    /** Tabela sem as colunas de unidade (regra pura): "Ocultar unidade" mostra o valor sem dizer qual unidade pagou. */
    public static function withoutUnit(array $table): array
    {
        $keep = array_keys(array_filter($table['header'], static fn ($label): bool => !in_array((string)$label, self::UNIT_HEADERS, true)));
        return ['header' => array_values(array_map(static fn (int $i) => $table['header'][$i], $keep)),
            'rows' => array_map(static fn (array $row): array => array_values(array_map(static fn (int $i): string => (string)($row[$i] ?? ''), $keep)), $table['rows'])];
    }

    /** Troca a extensão .csv do nome de arquivo antigo pela do formato pedido (regra pura). */
    public static function rename(string $filename, string $format): string
    {
        return (string)preg_replace('/\.csv$/', '', $filename) . '.' . $format;
    }

    /** Envia o arquivo (cabeçalhos HTTP + conteúdo já gerado). */
    public static function send(string $format, string $content, string $filename): void
    {
        header('Content-Type: ' . self::FORMATS[$format]);
        header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    /** HTML do PDF (público para o teste conferir o que vai no documento). */
    public static function html(array $table, array $meta): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $issuer = self::issuer();
        $rows = array_slice($table['rows'], 0, self::MAX_PDF_ROWS);
        $cut = count($table['rows']) - count($rows);
        $numeric = [];
        foreach ($table['header'] as $i => $label) {
            $numeric[$i] = $rows !== [] && array_filter($rows, static fn (array $r): bool => ($r[$i] ?? '') !== '' && self::number(self::plain((string)$r[$i])) === null) === [];
        }
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>'
            . '@page{margin:28px 28px 40px}body{font-family:"DejaVu Sans",sans-serif;font-size:8.5px;color:#1f2a24}'
            . '.issuer{text-align:right;font-size:8.5px;line-height:1.45}.issuer strong{font-size:10px}.logo{float:left;height:34px}'
            . '.date{text-align:right;margin:10px 0 2px}.head{text-align:center;margin:4px 0 12px}.head .condo{font-size:10px;font-weight:bold}'
            . '.head .title{font-size:11px;font-weight:bold;margin-top:2px}table{width:100%;border-collapse:collapse}'
            . 'thead{display:table-header-group}th{text-align:left;font-weight:bold;border-bottom:1px solid #9aa59f;padding:5px 4px}'
            . 'td{padding:5px 4px;border-bottom:1px solid #e3e7e4;vertical-align:top}.num{text-align:right;white-space:nowrap}'
            . '.empty,.cut{text-align:center;color:#5d6b63;padding:14px}</style></head><body>';
        $logo = self::logo();
        $html .= '<div class="issuer">' . ($logo !== '' ? '<img class="logo" src="' . $logo . '" alt="">' : '')
            . '<strong>' . $e($issuer['name']) . '</strong><br>CNPJ: ' . $e($issuer['document']) . '<br>' . $e($issuer['address']) . '</div>';
        $html .= '<div class="date">' . $e($meta['date'] ?? date('d/m/Y')) . '</div><div class="head">'
            . '<div class="condo">' . $e((string)($meta['condominium'] ?? 'Todos os condomínios')) . '</div>'
            . '<div class="title">' . $e($meta['title']) . '</div></div>';
        $html .= '<table><thead><tr>';
        foreach ($table['header'] as $i => $label) {
            $html .= '<th' . ($numeric[$i] ? ' class="num"' : '') . '>' . $e((string)$label) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $html .= '<tr>';
            foreach ($table['header'] as $i => $label) {
                $html .= '<td' . ($numeric[$i] ? ' class="num"' : '') . '>' . $e(self::plain((string)($row[$i] ?? ''))) . '</td>';
            }
            $html .= '</tr>';
        }
        if ($rows === []) {
            $html .= '<tr><td class="empty" colspan="' . max(1, count($table['header'])) . '">Nenhum registro.</td></tr>';
        }
        $html .= '</tbody></table>';
        if ($cut > 0) {
            $html .= '<p class="cut">' . $cut . ' linha(s) a mais não couberam no PDF. Baixe em Excel para ver tudo.</p>';
        }
        return $html . '</body></html>';
    }

    private static function pdf(array $table, array $meta): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($table, $meta), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $height = $canvas->get_height();
        $canvas->page_text(28, $height - 26, self::issuer()['site'], $font, 7.5, [0.36, 0.42, 0.39]);
        $canvas->page_text($canvas->get_width() - 40, $height - 26, '{PAGE_NUM}', $font, 7.5, [0.36, 0.42, 0.39]);
        return (string)$dompdf->output();
    }

    private static function xlsx(array $table, array $meta): string
    {
        $issuer = self::issuer();
        $lines = [[$issuer['name']], ['CNPJ: ' . $issuer['document']], [$issuer['address']], [],
            [(string)($meta['condominium'] ?? 'Todos os condomínios')], [$meta['title']], [$meta['date'] ?? date('d/m/Y')], []];
        $headerRow = count($lines) + 1;
        $lines[] = array_map('strval', $table['header']);
        foreach ($table['rows'] as $row) {
            $lines[] = array_map(static fn ($c): string => self::plain((string)$c), $row);
        }
        $x = static fn (string $t): string => htmlspecialchars((string)preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', $t), ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $col = static function (int $i): string {
            $name = '';
            for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
                $name = chr(65 + ($i - 1) % 26) . $name;
            }
            return $name;
        };
        $data = '';
        foreach ($lines as $r => $line) {
            $n = $r + 1;
            $data .= '<row r="' . $n . '">';
            foreach ($line as $c => $cell) {
                $ref = $col($c) . $n;
                $style = $n === $headerRow || $n <= 2 || $n === 6 ? ' s="1"' : '';
                $number = $n > $headerRow ? self::number($cell) : null;
                $data .= $number !== null
                    ? '<c r="' . $ref . '"' . (is_float($number) ? ' s="2"' : '') . '><v>' . $number . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $x($cell) . '</t></is></c>';
            }
            $data .= '</row>';
        }
        $widths = '';
        foreach ($table['header'] as $c => $label) {
            $widths .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . min(60, max(10, mb_strlen((string)$label) + 4)) . '" customWidth="1"/>';
        }
        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="' . $headerRow . '" topLeftCell="A' . ($headerRow + 1) . '" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($widths !== '' ? '<cols>' . $widths . '</cols>' : '') . '<sheetData>' . $data . '</sheetData></worksheet>';
        $sheetName = $x(mb_substr((string)preg_replace('/[\\\\\/?*\[\]:]/u', ' ', $meta['title']), 0, 31) ?: 'Lista');
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
                . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
                . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
                . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
                . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $sheetName . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
                . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
                . '<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
                . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
                . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
                . '<cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
                . '<xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs>'
                . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheet,
        ];
        $path = (string)tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível gerar a planilha.');
        }
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $content = (string)file_get_contents($path);
        @unlink($path);
        return $content;
    }

    /** @return array{name:string, document:string, address:string, site:string} */
    public static function issuer(): array
    {
        $issuer = self::ISSUER;
        $street = defined('CONF_SITE_ADDR_STREET') ? trim((string)CONF_SITE_ADDR_STREET) : '';
        if ($street !== '') {
            $number = trim((string)(defined('CONF_SITE_ADDR_NUMBER') ? CONF_SITE_ADDR_NUMBER : ''));
            $parts = [$street . ($number !== '' ? ', ' . $number : '')];
            foreach (['CONF_SITE_ADDR_COMPLEMENT', 'CONF_SITE_ADDR_DISTRICT', 'CONF_SITE_ADDR_ZIPCODE', 'CONF_SITE_ADDR_CITY', 'CONF_SITE_ADDR_STATE'] as $const) {
                $value = defined($const) ? trim((string)constant($const)) : '';
                if ($value !== '') {
                    $parts[] = $value;
                }
            }
            $issuer['address'] = implode(' - ', $parts);
        }
        return $issuer;
    }

    public static function logo(): string
    {
        $path = dirname(__DIR__, 3) . '/container/web/default/v1/assets/images/logo-connect-condominios.svg';
        return is_file($path) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($path)) : '';
    }
}
