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
    $route->get("/seguranca", "Security:show");
    $route->post("/seguranca", "Security:update");
    $route->get("/seguranca/codigos", "Panel:recoveryCodes");
    $route->post("/contexto/condominio", "Panel:setCondominium");

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
    $route->get("/checklist/catalogo", "OnboardingCatalog:index");
    $route->post("/checklist/catalogo", "OnboardingCatalog:create");
    $route->post("/checklist/catalogo/{id}", "OnboardingCatalog:update");

    // Cadastro rápido de unidades (ADR 0140): antes do motor, para /{id} não capturar a rota.
    $route->get(\Source\Controllers\Erp\Connect\UnitBatchScreen::ROUTE, "UnitBatchScreen:show", $erpName('erp.units.batch'));

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

    // Chamados (CN-004, ADR 0034): abertura para o Help Desk, mesmo serviço da API /api/v1/tickets.
    $route->get("/operacional/chamados", "Tickets:index");
    $route->post("/operacional/chamados", "Tickets:store");

    // Fechamento mensal (AP-030): a tela é a página do catálogo (dados reais em Panel); fechar e
    // reabrir valem para o condomínio da barra superior (ADR 0032), com CSRF.
    $route->post("/financeiro/fechamento-mensal/fechar", "MonthClosings:close");
    $route->post("/financeiro/fechamento-mensal/reabrir", "MonthClosings:reopen");

    // Agendamentos Pendentes (AP-114, ADR 0070): a tela é a página do catálogo; confirmar e recusar
    // mudam a situação da reserva (AP-113) pelo motor, com CSRF.
    $route->post("/operacional/agendamentos-pendentes/{id}/confirmar", "PendingReservations:confirm");
    $route->post("/operacional/agendamentos-pendentes/{id}/recusar", "PendingReservations:refuse");

    // Fila de avisos (CN-005, ADR 0055 e 0072): lista com a trilha e reenvio manual com CSRF.
    $route->get("/configuracoes/fila-de-avisos", "NotificationQueue:index");
    $route->post("/configuracoes/fila-de-avisos/{id}/reenviar", "NotificationQueue:retry");

    // Lançar despesa e receita (AP-024 e AP-025, ADR 0057 e 0073): abrem o "novo" de contas a pagar e
    // a receber, que é o mesmo lançamento com a natureza fixa.
    foreach (\Source\Controllers\Erp\Connect\EntryShortcutScreens::NEW_ENTRY_ROUTES as $erpFrom => $erpTo) {
        $route->get($erpFrom, static function () use ($erpTo): void {
            redirect(erp_url($erpTo));
        });
    }

    // Uma rota por página do catálogo (exceto as que têm controller próprio, registradas acima).
    foreach ($erpCatalog['pages'] as $pageId => $page) {
        if (in_array($page['route'], ['/dashboard', '/condominios', '/operacional/vistorias', '/operacional/chamados', '/configuracoes/fila-de-avisos'], true) || in_array($pageId, $erpRegistryPages, true)
            || isset(\Source\Controllers\Erp\Connect\EntryShortcutScreens::NEW_ENTRY_ROUTES[$page['route']])) {
            continue;
        }
        $route->get($page['route'], static function () use ($pageId): void {
            (new Panel())->page($pageId);
        }, $erpName('erp.' . strtolower($pageId)));
    }

    // Depois das páginas do catálogo: /condominios/{id} não pode capturar /condominios/unidades etc.
    $route->get("/condominios/{id}", "Condominiums:show");
}

unset($erpFrom, $erpTo, $erpCatalog, $erpPageRoutes, $erpRegistries, $erpRegistryPages, $registrySlug, $registry, $base, $erpBases, $erpBase, $erpIndex, $erpName);
$route->namespace(null);
$route->group(null);
