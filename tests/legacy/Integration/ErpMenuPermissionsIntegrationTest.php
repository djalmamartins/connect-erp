<?php

declare(strict_types=1);

namespace MovesOSTests\Integration;

use MovesOSTests\TestCase;
use Source\Controllers\Erp\Connect\Panel;
use Source\Models\User;
use Source\Support\Access;
use Source\Support\Erp\CondominiumContext;

/**
 * Menu da equipe (ADR 0685): toda tela que dá "Sem permissão" para quem só tem erp.access precisa estar em
 * Panel::pagePermissions com essa mesma permissão, senão o menu mostra um item que abre 403.
 */
final class ErpMenuPermissionsIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        CondominiumContext::reset();
        $_GET = [];
        parent::tearDown();
    }

    public function testEveryForbiddenScreenIsHiddenFromTheMenu(): void
    {
        $this->registerMigrationPermissions();
        $this->pdo->prepare('INSERT INTO condominiums (name, status) VALUES (?, ?)')->execute(['Alpha', 'active']);
        $condominium = (int)$this->pdo->lastInsertId();
        $userId = $this->createUser(['level' => 2]);
        $this->pdo->exec("INSERT INTO access_roles (name, slug, level) VALUES ('Só ERP', 'erp_only_test', 30)");
        $this->pdo->exec("INSERT INTO access_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM access_roles r JOIN access_permissions p ON p.slug = 'erp.access' WHERE r.slug = 'erp_only_test'");
        $this->pdo->prepare("INSERT INTO access_user_roles (user_id, role_id) SELECT ?, id FROM access_roles WHERE slug = 'erp_only_test'")->execute([$userId]);
        $this->pdo->prepare('INSERT INTO user_condominium_access (user_id, condominium_id) VALUES (?, ?)')->execute([$userId, $condominium]);
        $_SESSION['authUser'] = $userId;
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $user = (new User())->findById($userId);
        $catalog = require dirname(__DIR__, 3) . '/container/erp/connect/v1/components/layout/catalog.php';
        $permissions = Panel::pagePermissions();

        $shownButForbidden = [];
        foreach ([null, $condominium] as $context) {
            CondominiumContext::reset();
            if ($context !== null) {
                CondominiumContext::use([['id' => $condominium, 'name' => 'Alpha']], $context);
            }
            foreach (array_keys($catalog['pages']) as $pageId) {
                Access::clear();
                $_GET = [];
                $mark = Access::deniedMark();
                ob_start();
                try {
                    [$template] = (new Panel())->pageView($user, $pageId);
                } finally {
                    ob_end_clean();
                }
                if ($template !== 'pages/system/state') {
                    continue;
                }
                // erp.partner.view é a pergunta "é conta só de imobiliária?" da moldura, não a permissão da tela.
                $denied = array_values(array_diff(Access::deniedSince($mark), ['erp.partner.view']));
                if ($denied !== [] && !in_array($permissions[$pageId] ?? null, $denied, true)) {
                    $shownButForbidden[$pageId] = implode(', ', $denied);
                }
            }
        }
        CondominiumContext::reset();
        self::assertSame([], $shownButForbidden, 'Tela aparece no menu mas abre sem permissão: incluir em Panel::PAGE_PERMISSIONS.');
    }
}
