<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Envio de arquivos e seletor de datas padrão em todo o sistema (ADR 0628; pedido do Djalma em 20/11/2026).
 * Os componentes ficam em container/shared/assets/connect-fields e entram em todos os layouts pelo helper
 * connect_fields_assets(); os campos continuam sendo <input type="file|date|datetime-local"> comuns.
 */
final class ConnectFieldsEverywhereTest extends TestCase
{
    private const DIR = 'container/shared/assets/connect-fields';

    /** Layouts que montam páginas com formulário: ERP, Studio/Help Desk, Central de Suporte e app web do morador. */
    private const LAYOUTS = [
        'container/erp/connect/v1/default.php',
        'container/cms/studio/v1/layouts/studio.php',
        'container/web/support/v1/layouts/theme.php',
        'container/web/support/v1/pages/widget.php',
        'container/app/connect/v1/default.php',
        'container/app/connect/v1/partials/bare.php',
    ];

    /** Seletores de foto de perfil continuam com a prévia redonda própria (decisão da ADR 0628). */
    private const OPT_OUT = [
        'container/cms/studio/v1/components/users/form.php',
        'container/app/connect/v1/pages/welcome.php',
        'container/app/connect/v1/pages/profile.php',
    ];

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function file(string $path): string
    {
        return (string)file_get_contents($this->root() . '/' . $path);
    }

    /** @return list<string> */
    private function templates(): array
    {
        $files = [];
        foreach (['container/app', 'container/cms', 'container/web', 'container/erp'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string)$file);
                if (str_ends_with($path, '.php')) {
                    $files[] = substr($path, strlen($this->root()) + 1);
                }
            }
        }
        sort($files);
        return $files;
    }

    public function testEveryLayoutLoadsTheSharedComponents(): void
    {
        foreach (self::LAYOUTS as $layout) {
            $html = $this->file($layout);
            self::assertStringContainsString("connect_fields_assets('css')", $html, $layout);
            self::assertStringContainsString("connect_fields_assets('js')", $html, $layout);
            self::assertLessThan(strpos($html, '</head>'), strpos($html, "connect_fields_assets('css')"), $layout . ': CSS no <head>');
            self::assertLessThan(strpos($html, '</body>'), strpos($html, "connect_fields_assets('js')"), $layout . ': JS antes do </body>');
        }
    }

    public function testHelperPointsToTheThreeFiles(): void
    {
        $helpers = $this->file('source/Boot/Helpers.php');
        self::assertStringContainsString('function connect_fields_assets(string $part): string', $helpers);
        foreach (['connect-fields.css', 'connect-upload.js', 'connect-datepicker.js'] as $name) {
            self::assertFileExists($this->root() . '/' . self::DIR . '/' . $name);
            self::assertStringContainsString("'" . $name . "'", $helpers);
        }
    }

    public function testUploadComponentContract(): void
    {
        $js = $this->file(self::DIR . '/connect-upload.js');
        foreach ([
            'window.ConnectUpload = api',
            "'Tamanho máximo: '",           // limite visível
            "'dragenter'", "'drop'",          // arrastar e soltar
            "'Remover '",                     // remover cada arquivo
            "'Tentar de novo: '",             // falha com tentar de novo
            "setAttribute('role', 'progressbar')",
            "'aria-live'",
            'data-cx-upload-queue',           // um envio por arquivo
            "'data-cx-upload') === 'off'",
            'new DataTransfer()',             // o input original recebe os arquivos
        ] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
    }

    public function testDatepickerContract(): void
    {
        $js = $this->file(self::DIR . '/connect-datepicker.js');
        foreach ([
            'window.ConnectDatepicker = api',
            "['de', 'ate']", "['date_from', 'date_to']", "['starts_at', 'ends_at']",
            "data-cx-range",
            "'(max-width: 640px)'",           // dois meses no computador, um no celular
            "'PageUp'", "'Escape'", 'ArrowLeft: -1',
            "setAttribute('role', 'grid')",
            "'aria-selected'",
            "'dd/mm/aaaa'",
            "closest('dialog[open]')",
        ] as $needle) {
            self::assertStringContainsString($needle, $js, $needle);
        }
    }

    public function testNoBrowserDialogsInTheComponents(): void
    {
        foreach (['connect-upload.js', 'connect-datepicker.js'] as $name) {
            $code = (string)preg_replace('~^\s*(\*|//|/\*).*$~m', '', $this->file(self::DIR . '/' . $name));
            self::assertDoesNotMatchRegularExpression('/(?<![\w.$])(?:window\s*\.\s*)?(?:alert|confirm|prompt)\s*\(/', $code, $name);
        }
    }

    public function testColorsFollowEachSystem(): void
    {
        $css = $this->file(self::DIR . '/connect-fields.css');
        foreach (['--erp-brand', '--studio-primary', '--app-brand', '--hc-brand'] as $var) {
            self::assertStringContainsString('var(' . $var, $css, $var);
        }
        self::assertStringContainsString('prefers-reduced-motion', $css);
    }

    public function testOldHandMadePickersAreGone(): void
    {
        $offenders = [];
        foreach ($this->templates() as $path) {
            $code = $this->file($path);
            if (preg_match('/data-disk-drop|class="erp-dropzone"|class="studio-cover-upload"|class="studio-ticket-attachments"|data-attachment-progress|type="file"[^>]*onchange="this\.form\.requestSubmit\(\)"/', $code)) {
                $offenders[] = $path;
            }
            if (!in_array($path, self::OPT_OUT, true) && str_contains($code, 'data-cx-upload="off"')) {
                $offenders[] = $path . ' (desligou o envio padrão)';
            }
        }
        self::assertSame([], $offenders);
        foreach (self::OPT_OUT as $path) {
            self::assertStringContainsString('data-cx-upload="off"', $this->file($path), $path);
        }
    }

    public function testStudioAjaxFormsPaintTheProgress(): void
    {
        $js = $this->file('container/cms/studio/v1/assets/js/scripts.js');
        self::assertStringContainsString('window.ConnectUpload.formProgress(form[0], position, total)', $js);
        self::assertStringContainsString('window.ConnectUpload.formFail(', $js);
        self::assertStringContainsString('ConnectUpload', $this->file('container/cms/studio/v1/assets/studio.min.js'));
        $media = $this->file('container/cms/studio/v1/components/media/home.php');
        self::assertStringContainsString('data-cx-upload-queue data-cx-upload-auto', $media);
        self::assertStringContainsString('class="ajax_off"', $media);
    }

    public function testErpUploadsUseTheComponentApi(): void
    {
        $js = $this->file('container/erp/connect/v1/assets/js/erp.js');
        self::assertGreaterThanOrEqual(3, substr_count($js, 'window.ConnectUpload'));
        self::assertStringContainsString('upload.fail(input, file, error.message, retry)', $js);
        self::assertStringContainsString('ConnectUpload', $this->file('container/erp/connect/v1/assets/erp.min.js'));
    }
}
