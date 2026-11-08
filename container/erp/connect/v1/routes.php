<?php

declare(strict_types=1);

/**
 * Connect ERP v1 — rotas (MovesCode Router via Source\Core\Router).
 * Incluído por index.php a partir de moves_container_path('erp', CONF_VIEW_ERP).
 * As páginas vêm do catálogo validado (components/layout/catalog.php, gerado por
 * tools/erp-catalog.py; matriz em docs/ERP_V1_ROUTE_MATRIX.md).
 * Condomínios AP-004 (cadastro e edição, ADR 0011) e checklist de chegada (ADR 0013).
 */

use Source\Controllers\Erp\Connect\Panel;
use Source\Controllers\Erp\Connect\Registry;
use Source\Services\Erp\Registry\Definitions as RegistryDefinitions;

$erpCatalog = require __DIR__ . '/components/layout/catalog.php';

$route->namespace("Source\\Controllers\\Erp\\Connect");

// No host do ERP (erp.connect.lab) as rotas ficam na raiz e também em /erp, para links
// antigos e para o retorno do 2FA (/studio/login/2fa?next=erp). Nos demais hosts, só /erp.
$erpBases = erp_host() ? ['', '/erp'] : ['/erp'];
$erpPageRoutes = array_column($erpCatalog['pages'], 'route');
$erpRegistries = RegistryDefinitions::all();
$erpRegistryPages = array_column($erpRegistries, 'page');
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
    // Cadastro e edição (ADR 0011) e checklist de chegada (ADR 0013). /novo antes das páginas
    // do catálogo; as rotas /{id}/... têm mais segmentos e não colidem com /condominios/unidades.
    $route->get("/condominios/novo", "Condominiums:create");
    $route->post("/condominios/novo", "Condominiums:store");
    $route->get("/condominios/{id}/editar", "Condominiums:edit");
    $route->post("/condominios/{id}/editar", "Condominiums:update");
    $route->get("/condominios/{id}/checklist", "CondominiumChecklist:show");
    $route->post("/condominios/{id}/checklist/iniciar", "CondominiumChecklist:start");
    $route->post("/condominios/{id}/checklist/itens", "CondominiumChecklist:add");
    $route->post("/condominios/{id}/checklist/itens/{item}", "CondominiumChecklist:update");

    // Cadastros com dados reais (motor único RegistryService, ADR 0014): listagem,
    // novo, detalhe/edição e exclusão. "novo" vem antes de {id}.
    foreach ($erpRegistries as $registrySlug => $registry) {
        $base = $registry['route'];
        $route->get($base, static function () use ($registrySlug): void {
            (new Registry())->index($registrySlug);
        }, $erpName('erp.' . strtolower($registry['page'])));
        $route->post($base, static function (array $data) use ($registrySlug): void {
            unset($data['id']);
            (new Registry())->save($registrySlug, $data);
        });
        $route->get($base . '/novo', static function () use ($registrySlug): void {
            (new Registry())->create($registrySlug);
        });
        $route->get($base . '/{id}', static function (array $data) use ($registrySlug): void {
            (new Registry())->edit($registrySlug, $data);
        });
        $route->post($base . '/{id}', static function (array $data) use ($registrySlug): void {
            (new Registry())->save($registrySlug, $data);
        });
        $route->post($base . '/{id}/excluir', static function (array $data) use ($registrySlug): void {
            (new Registry())->destroy($registrySlug, $data);
        });
    }

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

    // Uma rota por página do catálogo (exceto Dashboard e Condomínios, registrados acima).
    foreach ($erpCatalog['pages'] as $pageId => $page) {
        if (in_array($page['route'], ['/dashboard', '/condominios'], true) || in_array($pageId, $erpRegistryPages, true)) {
            continue;
        }
        $route->get($page['route'], static function () use ($pageId): void {
            (new Panel())->page($pageId);
        }, $erpName('erp.' . strtolower($pageId)));
    }

    // Depois das páginas do catálogo: /condominios/{id} não pode capturar /condominios/unidades etc.
    $route->get("/condominios/{id}", "Condominiums:show");
}

unset($erpCatalog, $erpPageRoutes, $erpRegistries, $erpRegistryPages, $registrySlug, $registry, $base, $erpBases, $erpBase, $erpIndex, $erpName);
$route->namespace(null);
$route->group(null);
