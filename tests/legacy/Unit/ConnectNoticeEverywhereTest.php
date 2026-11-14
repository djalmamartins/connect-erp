<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Support\Message;

/**
 * Avisos no canto superior direito em todo o sistema (ADR 0120, pedido do Djalma em 11/11/2026): o ERP usa o
 * próprio erp.js; o Studio, o site e as telas de login usam o componente compartilhado connect-notice.js.
 */
final class ConnectNoticeEverywhereTest extends TestCase
{
    private function file(string $path): string
    {
        return (string)file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testServerMessagesAreMarkedForTheNotice(): void
    {
        $html = (new Message())->success('Usuário atualizado.')->render();
        self::assertMatchesRegularExpression("/class='message success[^']*' data-notice>/", $html);
        self::assertStringContainsString('atualizado.', $html);
        self::assertStringContainsString("class='message error icon-warning' data-notice", $this->file('container/cms/studio/v1/assets/js/scripts.js'), 'erro de requisição do Studio também vira aviso');
    }

    public function testSharedComponentConvertsOnlyMarkedMessages(): void
    {
        $js = $this->file('container/shared/assets/js/connect-notice.js');
        self::assertStringContainsString("node.matches('.message[data-notice]')", $js, 'estado vazio (div.message sem data-notice) não vira aviso');
        self::assertStringContainsString('new MutationObserver(', $js, 'mensagens que chegam por AJAX também viram aviso');
        self::assertStringContainsString("var TITLES = { success: 'Sucesso!', warning: 'Atenção!', error: 'Erro!', info: 'Informação' };", $js);
        self::assertStringContainsString("close.setAttribute('aria-label', 'Fechar aviso');", $js);
        self::assertStringContainsString("'cn-done-mask-' + (++seq)", $js, 'máscara única por aviso');
        self::assertStringNotContainsString('repeatCount', $js, 'a animação não repete');
        self::assertStringContainsString('window.ConnectNotice = { show: show };', $js);
    }

    public function testStudioSiteAndLoginLoadTheComponent(): void
    {
        self::assertStringContainsString('/container/shared/assets/js/connect-notice.js", $themeRoot . "/assets/js/scripts.js"', $this->file('source/Services/Minify/Studio.php'));
        self::assertStringContainsString('"/container/shared/assets/js/connect-notice.js",', $this->file('source/Services/Minify/Web.php'));
        self::assertStringContainsString("url('/container/shared/assets/js/connect-notice.js')", $this->file('container/cms/studio/v1/layouts/login.php'));
        self::assertStringContainsString('ConnectNotice', $this->file('container/cms/studio/v1/assets/studio.min.js'), 'bundle do Studio regerado');
        self::assertStringContainsString('ConnectNotice', $this->file('container/web/default/v1/assets/site.min.js'), 'bundle do site regerado');
    }
}
