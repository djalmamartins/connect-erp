<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ADRs 0098 e 0099: /verificar responde em qualquer host. As rotas ficam no index.php antes do
 * if (erp_host()), fora de qualquer bloco por host, e não no routes.php da web (que só carrega fora do ERP
 * e com o site ligado).
 */
final class DocumentVerifyRouteTest extends TestCase
{
    public function testRoutesAreRegisteredForEveryHost(): void
    {
        $index = (string)file_get_contents(dirname(__DIR__, 3) . '/index.php');
        $get = strpos($index, '$route->get("/verificar", "DocumentVerify:form");');
        $post = strpos($index, '$route->post("/verificar", "DocumentVerify:verify");');
        $hostSplit = strpos($index, 'if (erp_host()) {');
        self::assertNotFalse($get);
        self::assertNotFalse($post);
        self::assertNotFalse($hostSplit);
        self::assertLessThan($hostSplit, $get);
        self::assertLessThan($hostSplit, $post);

        $before = substr($index, 0, $get);
        $code = (string)preg_replace(['~/\*.*?\*/~s', '~//[^\n]*~', '~"(?:\\\\.|[^"\\\\])*"~', "~'(?:\\\\.|[^'\\\\])*'~"], '', $before);
        self::assertSame(substr_count($code, '{'), substr_count($code, '}'), 'As rotas não podem ficar dentro de um bloco condicional.');
        self::assertMatchesRegularExpression('~\$route->namespace\("Source\\\\\\\\Controllers\\\\\\\\Web\\\\\\\\Connect"\);\s*\$route->get\("/verificar"~', $index);
    }

    public function testWebRoutesDoNotDuplicateTheRoute(): void
    {
        $web = (string)file_get_contents(dirname(__DIR__, 3) . '/container/web/default/v1/routes.php');
        self::assertStringNotContainsString('/verificar', $web);
    }
}
