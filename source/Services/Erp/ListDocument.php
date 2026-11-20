<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use Dompdf\Dompdf;
use Dompdf\Options;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
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
    /** Linhas que cabem numa folha A4 deitada com o cabeçalho do modelo 3 (texto de uma linha por célula). */
    public const PDF_ROWS_PER_SHEET = 17;
    /** Folhas por Dompdf: lotes de 340 linhas ficam perto de 50 MB e 1 s (ADR 0383). */
    public const PDF_SHEETS_PER_BATCH = 20;
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
        // PDF abre no navegador (inline) para a pessoa ver antes de decidir baixar (pedido do Djalma, 16/11); Excel e CSV baixam.
        header('Content-Disposition: ' . ($format === 'pdf' ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $filename) . '"');
        header('Content-Length: ' . strlen($content));
        header('Cache-Control: no-store, private');
        header('X-Content-Type-Options: nosniff');
        echo $content;
    }

    /** HTML do PDF (público para o teste conferir o que vai no documento). */
    public static function html(array $table, array $meta, ?int $cut = null): string
    {
        $e = static fn (string $t): string => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $issuer = self::issuer();
        $rows = array_slice($table['rows'], 0, self::MAX_PDF_ROWS);
        $cut ??= count($table['rows']) - count($rows);
        $numeric = [];
        foreach ($table['header'] as $i => $label) {
            $numeric[$i] = $rows !== [] && array_filter($rows, static fn (array $r): bool => ($r[$i] ?? '') !== '' && self::number(self::plain((string)$r[$i])) === null) === [];
        }
        // Leiaute do modelo 3 (revisão de 15/11, pedido do Djalma de 16/11: "manter tudo igual"): logo à esquerda e os
        // dados da Connect centralizados à direita, linha escura, data à direita, condomínio e título centralizados em
        // negrito, tabela com cabeçalho em negrito entre linhas cinza e rodapé com linha, site e página em negrito itálico.
        $html = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><style>'
            . '@page{margin:40px 30px 52px}body{font-family:"DejaVu Sans",sans-serif;font-size:9.5px;color:#333}'
            . '.top{width:100%;border-collapse:collapse;border-bottom:1px solid #222}.top td{border:0;padding:0 0 10px;vertical-align:middle}'
            . '.top .brand{width:26%;text-align:center}.logo{height:38px}.issuer{text-align:center;font-size:9px;line-height:1.35;color:#222}'
            . '.issuer strong{display:block;font-size:14.5px;letter-spacing:.2px;color:#222;margin-bottom:2px}'
            . '.date{text-align:right;margin:5px 0 0;font-size:9.5px;color:#222}.head{text-align:center;margin:2px 0 12px;color:#222}'
            . '.head .condo{font-size:11px;font-weight:bold}.head .title{font-size:11px;font-weight:bold;margin-top:6px}'
            . 'table.list{width:100%;border-collapse:collapse}table.next{page-break-before:always}thead{display:table-header-group}'
            . 'table.list th{text-align:left;font-weight:bold;color:#222;border-top:1px solid #d6d6d6;border-bottom:1px solid #d6d6d6;padding:6px 8px}'
            . 'table.list td{padding:7px 8px;border-bottom:1px solid #dedede;vertical-align:middle}tr{page-break-inside:avoid}'
            . '.list td.sheet{padding:0;border:0}.num{text-align:right;white-space:nowrap}.empty,.cut{text-align:center;color:#666;padding:14px}</style></head><body>';
        // O cabeçalho inteiro (Connect, data, condomínio e título) fica no thead para se repetir em cada página, como no modelo.
        $logo = self::logo();
        $sheetHead = '<table class="top"><tr><td class="brand">' . ($logo !== '' ? '<img class="logo" src="' . $logo . '" alt="">' : '') . '</td>'
            . '<td class="issuer"><strong>' . $e($issuer['name']) . '</strong>CNPJ: ' . $e($issuer['document']) . '<br>' . $e($issuer['address']) . '</td></tr></table>';
        $sheetHead .= '<div class="date">' . $e($meta['date'] ?? date('d/m/Y')) . '</div><div class="head">'
            . '<div class="condo">' . $e((string)($meta['condominium'] ?? 'Todos os condomínios')) . '</div>'
            . '<div class="title">' . $e($meta['title']) . '</div></div>';
        $head = '<thead><tr><td class="sheet" colspan="' . max(1, count($table['header'])) . '">' . $sheetHead . '</td></tr><tr>';
        foreach ($table['header'] as $i => $label) {
            $head .= '<th' . ($numeric[$i] ? ' class="num"' : '') . '>' . $e((string)$label) . '</th>';
        }
        $head .= '</tr></thead>';
        // Uma tabela por folha (ADR 0383): o Dompdf gasta memória e tempo que crescem mais que a quantidade de linhas
        // numa tabela só (2.000 linhas: 1,2 GB e 98 s; 5.000 estourava 2 GB). Blocos de PDF_ROWS_PER_SHEET linhas, cada um
        // com o mesmo cabeçalho e começando numa folha nova, deixam o custo proporcional e a folha igual à de antes.
        $chunks = $rows === [] ? [[]] : array_chunk($rows, self::PDF_ROWS_PER_SHEET);
        foreach ($chunks as $index => $chunk) {
            $html .= '<table class="list' . ($index > 0 ? ' next' : '') . '">' . $head . '<tbody>';
            foreach ($chunk as $row) {
                $html .= '<tr>';
                foreach ($table['header'] as $i => $label) {
                    $html .= '<td' . ($numeric[$i] ? ' class="num"' : '') . '>' . $e(self::plain((string)($row[$i] ?? ''))) . '</td>';
                }
                $html .= '</tr>';
            }
            if ($chunk === []) {
                $html .= '<tr><td class="empty" colspan="' . max(1, count($table['header'])) . '">Nenhum registro.</td></tr>';
            }
            $html .= '</tbody></table>';
        }
        if ($cut > 0) {
            $html .= '<p class="cut">' . $cut . ' linha(s) a mais não couberam no PDF. Baixe em Excel para ver tudo.</p>';
        }
        return $html . '</body></html>';
    }

    private static function pdf(array $table, array $meta): string
    {
        \Source\Support\PdfTime::extend();
        // Lotes de folhas (ADR 0383): cada lote é um Dompdf separado, liberado antes do próximo, e os lotes são juntados
        // com o FPDI. A memória fica no tamanho de um lote, não da lista inteira; a numeração continua de um lote ao outro.
        $rows = array_slice($table['rows'], 0, self::MAX_PDF_ROWS);
        $batch = self::PDF_ROWS_PER_SHEET * self::PDF_SHEETS_PER_BATCH;
        if (count($rows) <= $batch) {
            return self::dompdf($table, $meta, 0);
        }
        $cut = count($table['rows']) - count($rows);
        $fpdi = new Fpdi();
        $fpdi->SetAutoPageBreak(false);
        $offset = 0;
        $parts = array_chunk($rows, $batch);
        foreach ($parts as $index => $part) {
            // A linha "N linha(s) a mais não couberam" só no último lote.
            $pdf = self::dompdf(['header' => $table['header'], 'rows' => $part], $meta, $offset, $index === count($parts) - 1 ? $cut : 0);
            $pages = $fpdi->setSourceFile(StreamReader::createByString($pdf));
            for ($page = 1; $page <= $pages; $page++) {
                $template = $fpdi->importPage($page);
                $size = $fpdi->getTemplateSize($template);
                $fpdi->AddPage($size['orientation'], [$size['width'], $size['height']]);
                $fpdi->useTemplate($template);
            }
            $offset += $pages;
            unset($pdf);
        }
        return $fpdi->Output('S');
    }

    private static function dompdf(array $table, array $meta, int $pageOffset, ?int $cut = null): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(self::html($table, $meta, $cut), 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();
        self::footer($dompdf, true, true, $pageOffset);
        return (string)$dompdf->output();
    }

    /**
     * Rodapé em cada página: linha escura, o site à esquerda e o número da página à direita. Nas listas (modelo 3) em
     * negrito itálico; na prestação de contas (PDF de outubro) em texto normal ($emphasis = false).
     */
    public static function footer(Dompdf $dompdf, bool $emphasis = true, bool $pageNumber = true, int $pageOffset = 0): void
    {
        $canvas = $dompdf->getCanvas();
        $metrics = $dompdf->getFontMetrics();
        $font = ($emphasis ? $metrics->getFont('DejaVu Sans', 'bold_italic') : null) ?? $metrics->getFont('DejaVu Sans');
        [$width, $height] = [$canvas->get_width(), $canvas->get_height()];
        $canvas->page_line(30, $height - 36, $width - 30, $height - 36, [0.13, 0.13, 0.13], 0.6);
        $canvas->page_text(30, $height - 30, self::issuer()['site'], $font, 8, [0.07, 0.07, 0.07]);
        if (!$pageNumber) {
            return;   // a numeração vem depois, na junção com os comprovantes (AccountabilityDocument::merge)
        }
        $canvas->page_script(static function (int $page, int $pages, $canvas, $metrics) use ($font, $width, $height, $pageOffset): void {
            $text = (string)($page + $pageOffset);
            $canvas->text($width - 30 - $metrics->getTextWidth($text, $font, 8), $height - 30, $text, $font, 8, [0.07, 0.07, 0.07]);
        });
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
