<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADRs 0098, 0099 e 0115: /verificar-certidao-debitos (e o /verificar antigo, que redireciona) responde em
 * qualquer host. As rotas ficam no index.php antes do
 * if (erp_host()), fora de qualquer bloco por host, e não no routes.php da web (que só carrega fora do ERP
 * e com o site ligado).
 */
final class DocumentVerifyRouteTest extends TestCase
{
    public function testRoutesAreRegisteredForEveryHost(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        $get = strpos($index, '$route->get("/verificar-certidao-debitos", "DocumentVerify:form");');
        $post = strpos($index, '$route->post("/verificar-certidao-debitos", "DocumentVerify:verify");');
        $legacyGet = strpos($index, '$route->get("/verificar", "DocumentVerify:legacy");');
        $legacyPost = strpos($index, '$route->post("/verificar", "DocumentVerify:verify");');
        self::assertNotFalse($legacyGet, 'Documentos antigos apontam para /verificar: ele continua e redireciona.');
        self::assertNotFalse($legacyPost);
        $hostSplit = strpos($index, 'if (erp_host()) {');
        self::assertNotFalse($get);
        self::assertNotFalse($post);
        self::assertNotFalse($hostSplit);
        self::assertLessThan($hostSplit, $get);
        self::assertLessThan($hostSplit, $post);
        self::assertLessThan($hostSplit, $legacyGet);
        self::assertLessThan($hostSplit, $legacyPost);

        $before = substr($index, 0, $get);
        $code = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~"(?:\\\\.|[^"\\\\])*"~', "~'(?:\\\\.|[^'\\\\])*'~"], '', $before);
        self::assertSame(substr_count($code, '{'), substr_count($code, '}'), 'As rotas não podem ficar dentro de um bloco condicional.');
        self::assertMatchesRegularExpression('~\$route->namespace\("Source\\\\\\\\Controllers\\\\\\\\Web\\\\\\\\Connect"\);\s*\$route->get\("/verificar-certidao-debitos"~', $index);
    }

    public function testWebRoutesDoNotDuplicateTheRoute(): void
    {
        $web = (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/default/v1/routes.php');
        self::assertStringNotContainsString('/verificar', $web);
    }

    public function testFormIsNotSentByAjaxAndLegacyRedirects(): void
    {
        $page = (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/default/v1/pages/verify.php');
        // O scripts.js do site manda todo form:not(.ajax_off) por AJAX esperando JSON; a resposta é HTML e o
        // carregamento não terminava (teste do Djalma, 09/11).
        self::assertMatchesRegularExpression('~<form class="ajax_off"~', $page);
        $controller = (string)file_get_contents(dirname(__DIR__, 3) . '/source/Controllers/Web/Connect/DocumentVerify.php');
        self::assertStringContainsString("header('Location: ' . url(DocumentVerification::PATH), true, 301);", $controller);
        self::assertStringContainsString("'action' => url(DocumentVerification::PATH)", $controller);
    }
}
