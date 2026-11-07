<?php

declare(strict_types=1);

/**
 * Connect ERP v1 — rotas (MovesCode Router via Source\Core\Router).
 * Incluído por index.php a partir de moves_container_path('erp', CONF_VIEW_ERP).
 * As páginas vêm do catálogo validado (components/layout/catalog.php, gerado por
 * tools/erp-catalog.py; matriz em docs/ERP_V1_ROUTE_MATRIX.md).
 */

use Source\Controllers\Erp\Connect\Panel;

$erpCatalog = require __DIR__ . '/components/layout/catalog.php';

$route->namespace("Source\\Controllers\\Erp\\Connect");
$route->group("/erp");

// Acesso: mesma autenticação do Studio (Auth + 2FA), destino e permissão do ERP.
$route->get("/", "Panel:root");
$route->get("/login", "Login:login");
$route->post("/login", "Login:login");
$route->post("/sair", "Login:logout");
$route->get("/sair", "Panel:root");
$route->get("/recuperar-senha", "Login:forgot");
$route->get("/seguranca/codigos", "Panel:recoveryCodes");

$route->get("/dashboard", "Panel:dashboard");

// Visão geral de cada módulo. O Dashboard não tem prefixo e, em Condomínios, a própria
// listagem (AP-004) ocupa o prefixo /condominios.
$erpPageRoutes = array_column($erpCatalog['pages'], 'route');
foreach ($erpCatalog['groups'] as $groupKey => $group) {
    if ($group['prefix'] === '' || in_array($group['prefix'], $erpPageRoutes, true)) {
        continue;
    }
    $route->get($group['prefix'], static function () use ($groupKey): void {
        (new Panel())->hub($groupKey);
    }, "erp.hub.{$groupKey}");
}

// Uma rota por página do catálogo (exceto o Dashboard, registrado acima).
foreach ($erpCatalog['pages'] as $pageId => $page) {
    if ($page['route'] === '/dashboard') {
        continue;
    }
    $route->get($page['route'], static function () use ($pageId): void {
        (new Panel())->page($pageId);
    }, 'erp.' . strtolower($pageId));
}

unset($erpCatalog, $erpPageRoutes);
$route->namespace(null);
$route->group(null);
