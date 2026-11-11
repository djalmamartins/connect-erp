<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use Dompdf\Dompdf;
use Dompdf\Options;
use Source\Core\View;
use Source\Services\Erp\Antivirus\ScanStatus;

/**
 * Livro caixa pronto para entregar (CN-007, ADR 0174): o mesmo documento (pages/reports/cash-book-document no tema
 * do ERP) serve para imprimir no navegador e para o PDF (dompdf, sem acesso remoto).
 *
 * - PDF: termo de abertura no cabeçalho, resumo, contas bancárias, movimento com saldo corrido e a referência dos
 *   anexos (A1, A2...), resumo por conta, pendências, assinaturas, índice dos anexos e cada anexo de imagem numa
 *   página (reduzido para no máximo 1600 px). Anexo em PDF não entra dentro do PDF (o dompdf não junta PDFs): o
 *   índice diz que ele está no pacote.
 * - Pacote (ZIP): o PDF do livro e todos os anexos numerados (anexos/A01-nota-fiscal-arquivo.pdf), para mandar ao
 *   síndico e ao conselho de uma vez.
 * - Anexo bloqueado pelo antivírus (ADR 0160) ou que sumiu do disco fica de fora, com aviso no índice.
 */
final class CashBookDocument
{
    public const MAX_EMBEDDED_IMAGES = 60;
    public const MAX_IMAGE_SIDE = 1600;
    public const MAX_PACKAGE_BYTES = 209715200; // 200 MB

    private string $theme;
    private EntryAttachments $attachments;

    public function __construct(?EntryAttachments $attachments = null, ?string $theme = null)
    {
        $this->attachments = $attachments ?? new EntryAttachments();
        $this->theme = rtrim($theme ?? moves_container_path('erp', CONF_VIEW_ERP), '/') . '/';
    }

    /**
     * Situação de cada anexo para o documento (regra pura): embed (imagem dentro do PDF), package (só no pacote),
     * blocked (antivírus) ou missing (arquivo não encontrado).
     *
     * @param list<array> $annexes com 'mime', 'scan_status' e 'exists'
     * @return list<array> os anexos com 'placement'
     */
    public static function placements(array $annexes, int $maxImages = self::MAX_EMBEDDED_IMAGES): array
    {
        $images = 0;
        foreach ($annexes as $i => $annex) {
            $placement = match (true) {
                !VirtualDisk::downloadable((string)($annex['scan_status'] ?? ScanStatus::UNSCANNED)) => 'blocked',
                empty($annex['exists']) => 'missing',
                str_starts_with((string)$annex['mime'], 'image/') && $images < $maxImages => 'embed',
                default => 'package',
            };
            if ($placement === 'embed') {
                $images++;
            }
            $annexes[$i]['placement'] = $placement;
        }
        return $annexes;
    }

    /** Nome do anexo dentro do pacote (regra pura): anexos/A01-nota-fiscal-nome-original.ext */
    public static function packageName(array $annex): string
    {
        $name = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', pathinfo((string)$annex['original_name'], PATHINFO_FILENAME));
        $slug = strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-'));
        $kind = str_replace('_', '-', (string)$annex['kind']);
        $ext = VirtualDisk::extension((string)$annex['original_name']);
        return sprintf('anexos/A%02d-%s%s%s', (int)$annex['number'], $kind, $slug !== '' ? '-' . substr($slug, 0, 60) : '', $ext !== '' ? '.' . $ext : '');
    }

    /** Anexos com o caminho absoluto e a situação (lê o disco). */
    public function prepare(array $book): array
    {
        $annexes = [];
        foreach ($book['annexes'] as $annex) {
            $path = isset($annex['path']) ? $this->attachments->absolute((string)$annex['path']) : null;
            $annexes[] = ['absolute' => $path, 'exists' => $path !== null] + $annex;
        }
        $book['annexes'] = self::placements($annexes);
        return $book;
    }

    public function html(array $book, bool $forPdf = false, ?string $printBack = null): string
    {
        return (new View($this->theme))->render('pages/reports/cash-book-document', [
            'book' => $book,
            'forPdf' => $forPdf,
            'printBack' => $printBack,
            'logo' => self::logo(),
            'imageSrc' => fn (array $annex): string => $forPdf ? self::imageData((string)$annex['absolute']) : '',
            'siteName' => defined('CONF_SITE_NAME') && CONF_SITE_NAME !== '' ? CONF_SITE_NAME : 'Connect Condomínios',
            'generatedAt' => date('d/m/Y H:i'),
        ]);
    }

    public function pdf(array $book): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('chroot', $this->theme);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($book, true), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = sprintf('Livro caixa · %s · %s', (string)$book['condominium']['name'], self::monthLabel((string)$book['competence']));
        $canvas->page_text(40, $canvas->get_height() - 28, $label . '   ·   Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.42, 0.48, 0.46]);
        return (string)$dompdf->output();
    }

    /**
     * Pacote ZIP com o PDF do livro e os anexos. Devolve o caminho do arquivo temporário (quem chama apaga).
     */
    public function package(array $book): string
    {
        $zipPath = (string)tempnam(sys_get_temp_dir(), 'livro-caixa-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível montar o pacote do livro caixa.');
        }
        $zip->addFromString(CashBook::filename($book), $this->pdf($book));
        $total = 0;
        foreach ($book['annexes'] as $annex) {
            if (!in_array($annex['placement'], ['embed', 'package'], true) || $annex['absolute'] === null) {
                continue;
            }
            $total += (int)$annex['size_bytes'];
            if ($total > self::MAX_PACKAGE_BYTES) {
                $zip->close();
                @unlink($zipPath);
                throw new \InvalidArgumentException('Os anexos do mês passam de 200 MB. Baixe o PDF e os anexos separadamente.');
            }
            $zip->addFile((string)$annex['absolute'], self::packageName($annex));
        }
        $zip->close();
        return $zipPath;
    }

    /** "2026-11" → "novembro de 2026" (regra pura). */
    public static function monthLabel(string $competence): string
    {
        $months = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
        [$year, $month] = array_map('intval', explode('-', $competence) + [1 => 1]);
        return ($months[$month - 1] ?? (string)$month) . ' de ' . $year;
    }

    /** Logo da Connect em data URI (o PDF não busca nada fora). */
    private static function logo(): string
    {
        $path = dirname(__DIR__, 3) . '/container/web/default/v1/assets/images/logo-connect-condominios.svg';
        return is_file($path) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($path)) : '';
    }

    /** Imagem reduzida (lado maior até MAX_IMAGE_SIDE) em JPEG data URI; sem GD, o arquivo original. */
    private static function imageData(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }
        $raw = (string)file_get_contents($path);
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->buffer($raw);
        if (!function_exists('imagecreatefromstring')) {
            return 'data:' . $mime . ';base64,' . base64_encode($raw);
        }
        $image = @imagecreatefromstring($raw);
        if ($image === false) {
            return '';
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1, self::MAX_IMAGE_SIDE / max($w, $h, 1));
        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int)round($w * $scale)), max(1, (int)round($h * $scale)));
            if ($resized !== false) {
                imagedestroy($image);
                $image = $resized;
            }
        }
        // Fundo branco para PNG com transparência.
        $canvas = imagecreatetruecolor(imagesx($image), imagesy($image));
        imagefill($canvas, 0, 0, (int)imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
        ob_start();
        imagejpeg($canvas, null, 82);
        $jpeg = (string)ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        return 'data:image/jpeg;base64,' . base64_encode($jpeg);
    }
}
