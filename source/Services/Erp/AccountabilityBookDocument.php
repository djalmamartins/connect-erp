<?php

declare(strict_types=1);

namespace Source\Services\Erp;

use Dompdf\Dompdf;
use Dompdf\Options;
use Source\Core\View;

/**
 * PDF e pacote da prestação de contas (ADR 0306), a partir do livro congelado do link público (ADR 0301).
 *
 * - PDF: o mesmo livro do link (mês ou ano), com a identidade da Connect, para guardar, imprimir ou mandar no grupo.
 *   Cada documento sai com o endereço completo no link público: no PDF, clicar no nome abre a nota ou o comprovante.
 * - Pacote (ZIP, só para a equipe): o PDF e todos os documentos do livro numerados na mesma ordem do livro
 *   (documentos/01-janeiro/003-nota-fiscal-copasa.pdf no anual). É o "livro do ano com as notas" para arquivar.
 *
 * O dompdf roda sem acesso remoto (a logo vai em data URI). Sem caminho de arquivo no PDF.
 */
final class AccountabilityBookDocument
{
    public const MAX_PACKAGE_BYTES = 209715200; // 200 MB, como o pacote do livro caixa

    private string $theme;

    public function __construct(?string $theme = null)
    {
        $this->theme = rtrim($theme ?? moves_container_path('erp', CONF_VIEW_ERP), '/') . '/';
    }

    /** Nome do arquivo (regra pura): prestacao-de-contas-condominio-x-2026-10.pdf */
    public static function filename(array $view, string $extension = 'pdf'): string
    {
        $name = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', (string)($view['condominium']['name'] ?? ''));
        $slug = substr(strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-')), 0, 60);
        $prefix = $view['kind'] === 'annual' ? 'livro-anual' : 'prestacao-de-contas';
        return $prefix . ($slug !== '' ? '-' . $slug : '') . '-' . preg_replace('/[^0-9-]/', '', (string)$view['period']) . '.' . $extension;
    }

    /**
     * Onde cada documento entra no pacote (regra pura), na ordem do livro: no mensal, documentos/001-tipo-nome.ext;
     * no anual, documentos/01-janeiro/001-tipo-nome.ext. Um documento repetido entra só na primeira vez.
     *
     * @return array<int, string> id do anexo => caminho no ZIP
     */
    public static function packagePaths(array $view): array
    {
        $months = $view['kind'] === 'annual' ? $view['book']['months'] : [$view['book']];
        $paths = [];
        foreach ($months as $month) {
            $folder = 'documentos/';
            if ($view['kind'] === 'annual') {
                $label = explode(' ', CashBookDocument::monthLabel((string)$month['competence']))[0];
                $folder .= substr((string)$month['competence'], 5, 2) . '-' . (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) . '/';
            }
            $n = 0;
            foreach ($month['sections'] as $section) {
                foreach ($section['categories'] as $category) {
                    foreach ($category['groups'] as $group) {
                        foreach ($group['items'] as $item) {
                            foreach ($item['documents'] as $doc) {
                                $id = (int)$doc['id'];
                                if (isset($paths[$id])) {
                                    continue;
                                }
                                $n++;
                                $name = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', pathinfo((string)$doc['name'], PATHINFO_FILENAME));
                                $slug = substr(strtolower(trim((string)preg_replace('/[^a-z0-9]+/i', '-', $name), '-')), 0, 60);
                                $ext = VirtualDisk::extension((string)$doc['name']);
                                $paths[$id] = sprintf('%s%03d-%s%s%s', $folder, $n, str_replace('_', '-', (string)$doc['kind']),
                                    $slug !== '' ? '-' . $slug : '', $ext !== '' ? '.' . $ext : '');
                            }
                        }
                    }
                }
            }
        }
        return $paths;
    }

    public function html(array $view, string $base): string
    {
        return (new View($this->theme))->render('pages/public/accountability-link-pdf', [
            'view' => $view,
            'base' => $base,
            'logo' => self::logo(),
            'siteName' => defined('CONF_SITE_NAME') && CONF_SITE_NAME !== '' ? CONF_SITE_NAME : 'Connect Condomínios',
            'generatedAt' => date('d/m/Y H:i'),
        ]);
    }

    public function pdf(array $view, string $base): string
    {
        $options = new Options();
        $options->set('tempDir', sys_get_temp_dir());
        $options->set('chroot', $this->theme);
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $options->set('defaultFont', 'DejaVu Sans');
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($this->html($view, $base), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $canvas = $dompdf->getCanvas();
        $font = $dompdf->getFontMetrics()->getFont('DejaVu Sans');
        $label = sprintf('%s · %s', (string)($view['condominium']['name'] ?? ''),
            $view['kind'] === 'annual' ? 'Livro anual de ' . $view['period'] : CashBookDocument::monthLabel((string)$view['period']));
        $canvas->page_text(34, $canvas->get_height() - 28, $label . '   ·   Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.40, 0.45, 0.43]);
        return (string)$dompdf->output();
    }

    /**
     * ZIP com o PDF e os documentos. Devolve o caminho do arquivo temporário (quem chama apaga).
     *
     * @param array<int, string> $files id do anexo => caminho absoluto (só os liberados)
     */
    public function package(array $view, string $base, array $files): string
    {
        $zipPath = (string)tempnam(sys_get_temp_dir(), 'prestacao-');
        $zip = new \ZipArchive();
        if ($zip->open($zipPath, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Não foi possível montar o pacote da prestação de contas.');
        }
        $zip->addFromString(self::filename($view), $this->pdf($view, $base));
        $total = 0;
        $missing = [];
        foreach (self::packagePaths($view) as $id => $inside) {
            $path = $files[$id] ?? null;
            if ($path === null || !is_file($path)) {
                $missing[] = $inside;
                continue;
            }
            $total += (int)filesize($path);
            if ($total > self::MAX_PACKAGE_BYTES) {
                $zip->close();
                @unlink($zipPath);
                throw new \InvalidArgumentException('Os documentos passam de 200 MB. Baixe o PDF e os documentos pelo link.');
            }
            $zip->addFile($path, $inside);
        }
        if ($missing !== []) {
            $zip->addFromString('documentos/LEIA-ME.txt', "Documentos que não entraram (removidos do Disco Virtual ou bloqueados pelo antivírus):\n"
                . implode("\n", $missing) . "\n");
        }
        $zip->close();
        return $zipPath;
    }

    private static function logo(): string
    {
        $path = dirname(__DIR__, 3) . '/container/web/default/v1/assets/images/logo-connect-condominios.svg';
        return is_file($path) ? 'data:image/svg+xml;base64,' . base64_encode((string)file_get_contents($path)) : '';
    }
}
