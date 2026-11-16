<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;

/**
 * Modal do sistema no lugar de alert, confirm e prompt do navegador em todo o sistema (ADR 0320, segue a 0316
 * do ERP; pedido do Djalma em 16/11/2026). Studio, Help Desk, site e app web do morador usam o componente
 * compartilhado connect-dialog.js; os avisos do canto ficam acima das modais (z-index 99999 e popover).
 */
final class SystemDialogsEverywhereTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__, 3);
    }

    private function file(string $path): string
    {
        return (string)file_get_contents($this->root() . '/' . $path);
    }

    /** Código-fonte do Connect: sem bundles gerados, bibliotecas de terceiros e a distribuição do Organic. */
    private function sources(): array
    {
        $files = [];
        foreach (['container/app', 'container/cms', 'container/web', 'container/send', 'container/erp', 'container/shared/assets/js'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                $path = str_replace('\\', '/', (string)$file);
                if (!preg_match('/\.(js|php)$/', $path) || str_contains($path, '.min.')) {
                    continue;
                }
                // Bundles gerados pelo Minify (scripts.js na raiz de assets do app) e bibliotecas de terceiros.
                if (preg_match('~/assets/scripts\.js$|/vendor/scripts/|/assets/vendor/(owl|scripts|tinymce)/~', $path)) {
                    continue;
                }
                $files[] = $path;
            }
        }
        sort($files);
        return $files;
    }

    public function testNoNativeBrowserDialogsAnywhere(): void
    {
        $native = '/(?<![\w.$])(?<!function )(?:window\s*\.\s*)?(?:alert|confirm|prompt)\s*\(/';
        $found = [];
        foreach ($this->sources() as $path) {
            foreach (preg_split('/\R/', (string)file_get_contents($path)) ?: [] as $n => $line) {
                $code = preg_replace('~^\s*(\*|//|/\*).*$~', '', $line) ?? $line;
                if (preg_match($native, $code)) {
                    $found[] = substr($path, strlen($this->root()) + 1) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $found, 'use ConnectDialog (Studio, site, app) ou ErpUI (ERP), com data-cn-confirm/data-app-confirm/data-erp-confirm');
    }

    public function testSharedDialogSaysWhetherItCanBeUndone(): void
    {
        $js = $this->file('container/shared/assets/js/connect-dialog.js');
        self::assertStringContainsString("el('strong', null, o.undo ? 'Dá para desfazer.' : 'Não dá para desfazer.')", $js);
        self::assertStringContainsString("var first = input || (tone === 'danger' && cancel ? cancel : ok);", $js, 'ação perigosa começa com o foco em Voltar');
        self::assertStringContainsString('window.ConnectDialog = { confirm: confirm, alert: alert, prompt: prompt, copy: copy, ask: ask };', $js);
        self::assertStringContainsString("body.appendChild(el('p', null, line.trim()));", $js, 'texto entra como texto, nunca como HTML');
        self::assertStringContainsString("form.requestSubmit(submitter && submitter.form === form ? submitter : undefined)", $js, 'reenvia pelo mesmo botão');
    }

    public function testEverySystemLoadsTheDialog(): void
    {
        self::assertStringContainsString('"/container/shared/assets/js/connect-dialog.js", $themeRoot . "/assets/js/scripts.js"', $this->file('source/Services/Minify/Studio.php'));
        self::assertStringContainsString('"/container/shared/assets/js/connect-dialog.js",', $this->file('source/Services/Minify/Web.php'));
        self::assertStringContainsString('"/container/shared/assets/js/connect-dialog.js");', $this->file('source/Services/Minify/App.php'));
        foreach (['container/cms/studio/v1/assets/studio.min.js', 'container/web/default/v1/assets/site.min.js', 'container/app/connect/v1/assets/scripts.js'] as $bundle) {
            self::assertStringContainsString('ConnectDialog', $this->file($bundle), $bundle . ' regerado');
        }
    }

    /** A distribuição do Organic V2 é gerada fora do repo: a sincronização precisa manter o ajuste (ADR 0321). */
    public function testOrganicDistributionUsesTheSystemDialog(): void
    {
        foreach (['organic.js', 'organic.min.js', 'organic.global.js', 'organic.global.min.js'] as $dist) {
            $js = $this->file('container/shared/assets/organic/' . $dist);
            self::assertSame(2, substr_count($js, 'window.ConnectDialog?window.ConnectDialog.confirm('), $dist . ': reaplique o ajuste do ADR 0321 depois de sincronizar o Organic');
        }
    }

    /** App React Native: janela própria (Dialog.tsx), dizendo se dá para desfazer; recado rápido por cima dela. */
    public function testNativeAppDialogSaysWhetherItCanBeUndone(): void
    {
        $dialog = $this->file('apps/morador/src/ui/Dialog.tsx');
        self::assertStringContainsString("{current.undo ? 'Dá para desfazer.' : 'Não dá para desfazer.'}", $dialog);
        self::assertStringContainsString('{current ? toastView : null}', $dialog);
        self::assertStringNotContainsString('Alert.alert', $dialog);
        foreach (['app/visitantes.tsx', 'app/reservas.tsx', 'app/(tabs)/perfil.tsx', 'src/ui/LockScreen.tsx'] as $screen) {
            self::assertMatchesRegularExpression('/\{ undo: (true|false), text: /', $this->file('apps/morador/' . $screen), $screen);
        }
    }

    public function testNoticesStayAboveTheModals(): void
    {
        $notice = $this->file('container/shared/assets/js/connect-notice.js');
        self::assertStringContainsString('z-index:99999;', $notice);
        self::assertStringContainsString("box.setAttribute('popover', 'manual');", $notice);
        self::assertMatchesRegularExpression('/\.app-toast \{[^}]*z-index: 99999;/', $this->file('container/app/connect/v1/assets/css/app.css'));
        self::assertStringContainsString("el.setAttribute('popover', 'manual');", $this->file('container/app/connect/v1/assets/js/app.js'));
    }
}
