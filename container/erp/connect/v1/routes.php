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

// No host do ERP (erp.connect.lab) as rotas ficam na raiz e também em /erp, para links
// antigos e para o retorno do 2FA (/studio/login/2fa?next=erp). Nos demais hosts, só /erp.
$erpBases = erp_host() ? ['', '/erp'] : ['/erp'];
$erpPageRoutes = array_column($erpCatalog['pages'], 'route');
foreach ($erpBases as $erpIndex => $erpBase) {
    $erpName = static fn (string $name): ?string => $erpIndex === 0 ? $name : null;
    $route->group($erpBase);

    // Acesso: mesma autenticação do Studio (Auth + 2FA), destino e permissão do ERP.
    $route->get("/", "Panel:root");
    $route->get("/login", "Login:login");
    $route->post("/login", "Login:login");
    $route->post("/sair", "Login:logout");
    $route->get("/sair", "Panel:root");
    $route->get("/recuperar-senha", "Login:forgot");
    $route->get("/seguranca/codigos", "Panel:recoveryCodes");

    $route->get("/dashboard", "Panel:dashboard");

    // Condomínios (AP-004) com dados reais e somente leitura: CondominiumScope → MariaDB,
    // o mesmo serviço da API /api/v1/condominiums (frente claude-dev, ADR 0007).
    $route->get("/condominios", "Condominiums:index");

    // Visão geral de cada módulo. O Dashboard não tem prefixo e, em Condomínios, a própria
    // listagem (AP-004) ocupa o prefixo /condominios.
    foreach ($erpCatalog['groups'] as $groupKey => $group) {
        if ($group['prefix'] === '' || in_array($group['prefix'], $erpPageRoutes, true)) {
            continue;
        }
        $route->get($group['prefix'], static function () use ($groupKey): void {
            (new Panel())->hub($groupKey);
        }, $erpName("erp.hub.{$groupKey}"));
    }

    // Vistoria presencial (CN-001) com dados reais: InspectionService → MariaDB, o mesmo
    // serviço da API /api/v1/inspections. Rotas fixas antes de /{id}.
    $route->get("/operacional/vistorias", "Inspections:index");
    $route->get("/operacional/vistorias/nova", "Inspections:create");
    $route->post("/operacional/vistorias", "Inspections:store");
    $route->get("/operacional/vistorias/configurar", "Inspections:settings");
    $route->post("/operacional/vistorias/modelos", "Inspections:saveTemplate");
    $route->post("/operacional/vistorias/planos", "Inspections:savePlan");
    $route->post("/operacional/vistorias/localizacao", "Inspections:saveLocation");
    $route->get("/operacional/vistorias/{id}", "Inspections:show");
    $route->post("/operacional/vistorias/{id}/itens/{item}", "Inspections:answer");
    $route->get("/operacional/vistorias/{id}/fotos/{photo}", "Inspections:photo");
    $route->post("/operacional/vistorias/{id}/fotos/{photo}/excluir", "Inspections:deletePhoto");
    $route->post("/operacional/vistorias/{id}/concluir", "Inspections:complete");
    $route->post("/operacional/vistorias/{id}/cancelar", "Inspections:cancel");
    $route->get("/operacional/vistorias/{id}/relatorio", "Inspections:report");
    $route->get("/operacional/vistorias/{id}/relatorio/pdf", "Inspections:reportPdf");
    $route->post("/operacional/vistorias/{id}/enviar", "Inspections:send");

    // Uma rota por página do catálogo (exceto as que têm controller próprio, registradas acima).
    foreach ($erpCatalog['pages'] as $pageId => $page) {
        if (in_array($page['route'], ['/dashboard', '/condominios', '/operacional/vistorias'], true)) {
            continue;
        }
        $route->get($page['route'], static function () use ($pageId): void {
            (new Panel())->page($pageId);
        }, $erpName('erp.' . strtolower($pageId)));
    }

    // Depois das páginas do catálogo: /condominios/{id} não pode capturar /condominios/unidades etc.
    $route->get("/condominios/{id}", "Condominiums:show");
}

unset($erpCatalog, $erpPageRoutes, $erpBases, $erpBase, $erpIndex, $erpName);
$route->namespace(null);
$route->group(null);
