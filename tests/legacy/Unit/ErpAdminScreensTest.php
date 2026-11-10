<?php

declare(strict_types=1);

namespace MovesOSTests\Unit;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\AdminScreens as Screens;
use Source\Controllers\Erp\Connect\Panel;

/**
 * Telas de administração, relatórios enviados, usuários e permissões do ERP (ADR 0150, sobre o ADR 0168): tela
 * própria só com dados, a mesma permissão de leitura da API, as mudanças só pela API e as regras puras.
 */
final class ErpAdminScreensTest extends TestCase
{
    private const TEMPLATE = 'container/erp/connect/v1/pages/settings/admin.php';

    public function testPagesTemplatesAndPermissions(): void
    {
        $permissions = Panel::pagePermissions();
        self::assertSame(['AP-091', 'AP-100', 'AP-149', 'AP-150'], array_keys(Screens::PAGES));
        foreach (Screens::PAGES as $page => $kind) {
            self::assertNull(Panel::ownTemplate($page, []));
            self::assertSame('pages/settings/admin', Panel::ownTemplate($page, ['adminReady' => true]));
            self::assertSame(Screens::VIEW[$page], $permissions[$page]);
        }
        self::assertSame(['erp.settings.view', 'erp.audit.view', 'erp.users.view', 'erp.permissions.view'], array_values(Screens::VIEW));
        self::assertSame(['AP-149' => 'erp.users.manage', 'AP-150' => 'erp.permissions.manage'], Screens::MANAGE);
    }

    public function testChangesGoThroughTheApiOnly(): void
    {
        $html = (string)file_get_contents(dirname(__DIR__, 3) . '/' . self::TEMPLATE);
        self::assertStringContainsString('data-erp-access', $html);
        self::assertStringContainsString('csrf_input()', $html);
        self::assertDoesNotMatchRegularExpression('/method="post"/i', $html, 'sem formulário que grava fora da API');
        self::assertDoesNotMatchRegularExpression('/<th(?=[\s>])(?![^>]*scope=)/', $html, 'todo <th> com scope');
        foreach (['/erp-users/invite', '/erp-permissions', "/erp-users/' . (int)\$u['id'] . '/deactivate", "/permissions'"] as $path) {
            self::assertStringContainsString($path, $html);
        }
        self::assertStringNotContainsString('password', $html, 'senha fica no Studio');
    }

    public function testPureRules(): void
    {
        $rows = [['id' => 3], ['id' => 8]];
        self::assertSame(8, Screens::selected('8', $rows));
        self::assertNull(Screens::selected('9', $rows));
        self::assertNull(Screens::selected('-1', $rows));
        self::assertNull(Screens::selected(null, $rows));
        self::assertSame('inherit', Screens::effect(null));
        self::assertSame('allow', Screens::effect('allow'));
        self::assertSame('inherit', Screens::effect('x'));
        self::assertSame('—', Screens::size(null));
        self::assertSame('1,5 KB', Screens::size(1536));
        self::assertSame('2,0 MB', Screens::size(2 * 1048576));
        self::assertSame('ar-aging_2', Screens::report('ar-aging_2'));
        self::assertNull(Screens::report('A B'));
        self::assertNull(Screens::report(['x']));
    }
}
