<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Modais do sistema no lugar dos avisos do navegador (ADR 0316, pedido do Djalma em 16/11/2026): no ERP,
 * nenhuma tela chama alert(), confirm() ou prompt(). A pergunta abre uma modal no visual do ERP que diz o que
 * vai acontecer e se dá para desfazer. Os avisos do canto (flash e toast) ficam acima das modais (z-index 99999).
 */
final class ErpSystemDialogsTest extends TestCase
{
    private const THEME = 'container/erp/connect/v1';

    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function file(string $path): string
    {
        return (string)file_get_contents($this->root() . '/' . $path);
    }

    /** @return list<string> */
    private function themeFiles(string $extension): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/' . self::THEME, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = (string)$file;
            if (str_ends_with($path, '.' . $extension) && !str_contains($path, '.min.')) {
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    public function testNoNativeBrowserDialogsInTheErp(): void
    {
        $native = '/(?<![\w.$])(?:window\s*\.\s*)?(?:alert|confirm|prompt)\s*\(/';
        $found = [];
        foreach (array_merge($this->themeFiles('js'), $this->themeFiles('php')) as $path) {
            foreach (preg_split('/\R/', (string)file_get_contents($path)) ?: [] as $n => $line) {
                $code = preg_replace('~^\s*(\*|//).*$~', '', $line) ?? $line; // comentários não contam
                if (preg_match($native, $code)) {
                    $found[] = substr($path, strlen($this->root()) + 1) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $found, 'use ErpUI.confirm / ErpUI.alert / ErpUI.copy ou data-erp-confirm');
    }

    public function testDialogComponentSaysWhatHappensAndWhetherItCanBeUndone(): void
    {
        $js = $this->file(self::THEME . '/assets/js/erp.js');
        self::assertStringContainsString('window.ErpUI.confirm = confirmDialog;', $js);
        self::assertStringContainsString('window.ErpUI.alert = alertDialog;', $js);
        self::assertStringContainsString('window.ErpUI.copy = copyDialog;', $js);
        self::assertStringContainsString("strong.textContent = o.undo ? 'Dá para desfazer.' : 'Não dá para desfazer.';", $js);
        self::assertStringContainsString("var first = o.focus || (tone === 'danger' && cancel ? cancel : ok);", $js, 'ação perigosa começa com o foco em Voltar');
        self::assertStringContainsString("dialog.className = 'erp-modal erp-ask erp-ask--' + tone;", $js, 'mesma modal do ERP');
        self::assertStringContainsString('p.textContent = line.trim();', $js, 'texto entra como texto, nunca como HTML');
        self::assertStringContainsString("askFrom(form, 'data-erp-confirm')", $js, 'formulários com data-erp-confirm usam a modal');
        self::assertStringContainsString("title: 'Cancelar o boleto ' + button.getAttribute('data-label') + ' no banco?'", $js, 'o exemplo do Djalma');
    }

    public function testNoticesStayAboveTheModals(): void
    {
        $css = $this->file(self::THEME . '/assets/css/erp.css');
        self::assertMatchesRegularExpression('/\.erp-toasts \{[^}]*z-index: 99999;/', $css);
        self::assertStringContainsString('.erp-toasts[popover] { inset: auto;', $css);
        $js = $this->file(self::THEME . '/assets/js/erp.js');
        self::assertStringContainsString("region.setAttribute('popover', 'manual');", $js);
        self::assertStringContainsString('raiseNotices();', $js);
    }

    public function testFormsThatConfirmSayWhetherItCanBeUndone(): void
    {
        foreach (['components/cards/entry-attachments.php', 'pages/inspections/show.php', 'pages/modules/closing.php', 'pages/operational/pending-reservations.php'] as $page) {
            $html = $this->file(self::THEME . '/' . $page);
            self::assertStringContainsString('data-erp-confirm=', $html, $page);
            self::assertMatchesRegularExpression('/data-confirm-undo="(sim|nao)"/', $html, $page);
        }
    }
}
