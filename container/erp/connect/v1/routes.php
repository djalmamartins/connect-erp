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
    // Menu novo ou atual (ADR 0398).
    $route->post("/preferencias/menu", "Panel:setMenu");

    $route->get("/dashboard", "Panel:dashboard");

    // Condomínios (AP-004) com dados reais e somente leitura: CondominiumScope → MariaDB,
    // o mesmo serviço da API /api/v1/condominiums (frente claude-dev, ADR 0007).
    $route->get("/condominios", "Condominiums:index");
    // Cadastro e edição (ADR 0011) e checklist de chegada (ADR 0013). /novo antes das páginas
    // do catálogo; as rotas /{id}/... têm mais segmentos e não colidem com /condominios/unidades.
    // O antigo "Painel do condomínio" (AP-002) foi fundido na visão geral (ADR 0154): links antigos voltam para ela.
    $route->get("/condominios/painel-do-condominio", "Condominiums:panelRedirect");
    $route->get("/condominios/novo", "Condominiums:create");
    $route->post("/condominios/novo", "Condominiums:store");
    $route->get("/condominios/{id}/editar", "Condominiums:edit");
    $route->post("/condominios/{id}/editar", "Condominiums:update");
    $route->post("/condominios/{id}/guia", "Condominiums:guide");
    $route->post("/condominios/{id}/guia/encerrar", "Condominiums:endGuide");
    $route->get("/condominios/{id}/checklist", "CondominiumChecklist:show");
    $route->post("/condominios/{id}/checklist/iniciar", "CondominiumChecklist:start");
    $route->post("/condominios/{id}/checklist/itens", "CondominiumChecklist:add");
    $route->post("/condominios/{id}/checklist/itens/{item}", "CondominiumChecklist:update");
    $route->get("/checklist/catalogo", "OnboardingCatalog:index");
    $route->post("/checklist/catalogo", "OnboardingCatalog:create");
    $route->post("/checklist/catalogo/{id}", "OnboardingCatalog:update");

    // Cadastro rápido de unidades (ADR 0140): antes do motor, para /{id} não capturar a rota.
    $route->get(\Source\Controllers\Erp\Connect\UnitBatchScreen::ROUTE, "UnitBatchScreen:show", $erpName('erp.units.batch'));
    // Termo de entrada e de saída (ADR 0311): antes do motor, para /{id} não capturar a rota.
    $route->get(\Source\Controllers\Erp\Connect\UnitMoveTermScreen::ROUTE, "UnitMoveTermScreen:edit", $erpName('erp.units.terms'));
    $route->post(\Source\Controllers\Erp\Connect\UnitMoveTermScreen::ROUTE, "UnitMoveTermScreen:save");
    $route->get(\Source\Controllers\Erp\Connect\UnitMoveTermScreen::PRINT_ROUTE, "UnitMoveTermScreen:show", $erpName('erp.units.term'));

    // Anexos dos lançamentos (ADR 0174) e downloads do livro caixa (CN-007): antes do motor e do catálogo.
    $route->post("/financeiro/lancamentos/{id}/anexos", "EntryAttachmentScreens:store");
    $route->get("/financeiro/lancamentos/{id}/anexos/{attachment}", "EntryAttachmentScreens:download");
    $route->post("/financeiro/lancamentos/{id}/anexos/{attachment}/excluir", "EntryAttachmentScreens:remove");
    $route->get("/prestacao-de-contas/livro-caixa/pdf", "CashBookScreens:pdf");
    $route->get("/prestacao-de-contas/livro-caixa/pacote", "CashBookScreens:package");
    $route->get("/prestacao-de-contas/livro-caixa/imprimir", "CashBookScreens:printView");
    // Prestação de contas no padrão do PDF de outubro (revisão de 15/11 item 21, ADR 0314).
    $route->get("/prestacao-de-contas/prestacao-de-contas/documento", "AccountabilityScreens:document");
    $route->get("/prestacao-de-contas/prestacao-de-contas/pdf", "AccountabilityScreens:documentPdf");
    // Link público da prestação de contas para os moradores (ADR 0301): gerar, enviar e revogar pelo livro caixa.
    $route->post("/prestacao-de-contas/livro-caixa/link", "CashBookScreens:generateLink");
    $route->post("/prestacao-de-contas/livro-caixa/link/{id}/enviar", "CashBookScreens:sendLink");
    $route->post("/prestacao-de-contas/livro-caixa/link/{id}/revogar", "CashBookScreens:revokeLink");
    $route->get("/prestacao-de-contas/livro-caixa/link/{id}/pacote", "CashBookScreens:packageLink");
    $route->post("/prestacao-de-contas/livro-caixa/feriados", "CashBookScreens:addHoliday");
    $route->post("/prestacao-de-contas/livro-caixa/feriados/{id}/remover", "CashBookScreens:removeHoliday");

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
    // Meu Dia (CN-010, ADR 0630): agenda e tarefas do dia de quem está logado.
    $route->get("/meu-dia", "MyDayScreens:index");
    $route->post("/meu-dia/compromissos", "MyDayScreens:store");
    $route->post("/meu-dia/compromissos/{id}/situacao", "MyDayScreens:status");
    $route->post("/meu-dia/sac", "MyDayScreens:sac");

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

    // Sino (ADR 0142): as notificações são as mesmas do Studio e do Help Desk.
    $route->get("/notificacoes/{id}", "Inbox:open");
    $route->post("/notificacoes/lidas", "Inbox:readAll");

    // Chamados (CN-004, ADR 0034): abertura para o Help Desk, mesmo serviço da API /api/v1/tickets.
    $route->get("/operacional/chamados", "Tickets:index");
    $route->post("/operacional/chamados", "Tickets:store");
    $route->post("/operacional/chamados/lote", "Tickets:bulk");
    $route->get("/operacional/chamados/{id}", "Tickets:show");
    $route->post("/operacional/chamados/{id}/responder", "Tickets:reply");
    $route->post("/operacional/chamados/{id}/atender", "Tickets:triage");
    $route->post("/operacional/chamados/{id}/atendimento", "Tickets:answer");

    // Fechamento mensal (AP-030): a tela é a página do catálogo (dados reais em Panel); fechar e
    // reabrir valem para o condomínio da barra superior (ADR 0032), com CSRF.
    $route->post("/financeiro/fechamento-mensal/fechar", "MonthClosings:close");
    $route->post("/financeiro/fechamento-mensal/reabrir", "MonthClosings:reopen");

    // Agendamentos Pendentes (AP-114, ADR 0070): a tela é a página do catálogo; confirmar e recusar
    // mudam a situação da reserva (AP-113) pelo motor, com CSRF.
    $route->post("/operacional/agendamentos-pendentes/{id}/confirmar", "PendingReservations:confirm");
    $route->post("/operacional/agendamentos-pendentes/{id}/recusar", "PendingReservations:refuse");

    // Honorários (CN-008, ADR 0402): contrato de administração por condomínio e pedido da nota (NFS-e), com CSRF.
    $route->get(\Source\Controllers\Erp\Connect\ManagementFeeScreens::ROUTE, "ManagementFeeScreens:index");
    $route->post("/condominios/honorarios/pedidos/{id}/cancelar", "ManagementFeeScreens:cancel");
    $route->post("/condominios/honorarios/{id}", "ManagementFeeScreens:save");
    $route->post("/condominios/honorarios/{id}/nota", "ManagementFeeScreens:request");

    // Dados pessoais (CN-009, ADR 0332): pedidos do titular (LGPD). Busca por POST para o CPF não ir na URL; CSRF em tudo.
    $route->get(\Source\Controllers\Erp\Connect\PrivacyScreens::ROUTE, "PrivacyScreens:index");
    $route->post(\Source\Controllers\Erp\Connect\PrivacyScreens::ROUTE, "PrivacyScreens:search");
    $route->post("/configuracoes/dados-pessoais/baixar", "PrivacyScreens:download");
    $route->post("/configuracoes/dados-pessoais/anonimizar", "PrivacyScreens:anonymize");

    // Propostas (CN-011, ADR 0644 e 0650): criar, gerar e enviar ficam só no ERP; CSRF e padrão PRG.
    $route->get(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE, "ProposalScreens:index");
    $route->get(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/p/{page}", "ProposalScreens:index");
    $route->get(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/nova", "ProposalScreens:create");
    $route->post(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/nova", "ProposalScreens:store");
    $route->get(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/{id}/pdf/{response}", "ProposalScreens:pdf");
    $route->post(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/{id}/resposta", "ProposalScreens:respond");
    $route->get(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/{id}", "ProposalScreens:show");
    $route->post(\Source\Controllers\Erp\Connect\ProposalScreens::ROUTE . "/{id}", "ProposalScreens:update");

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
        if (in_array($page['route'], ['/dashboard', '/meu-dia', '/condominios', '/operacional/vistorias', '/operacional/chamados', '/configuracoes/fila-de-avisos', '/condominios/honorarios', '/comunicacao/propostas', '/configuracoes/dados-pessoais'], true) || in_array($pageId, $erpRegistryPages, true)
            || isset(\Source\Controllers\Erp\Connect\EntryShortcutScreens::NEW_ENTRY_ROUTES[$page['route']])) {
            continue;
        }
        $route->get($page['route'], static function () use ($pageId): void {
            (new Panel())->page($pageId);
        }, $erpName('erp.' . strtolower($pageId)));
        // Paginação com endereço amigável (pedido do Djalma, 20/11/2026): /cobranca/boletos/p/12 em vez de ?pagina=12.
        $route->get($page['route'] . '/p/{page}', static function (array $data) use ($pageId): void {
            $_GET['pagina'] = max(1, (int)(filter_var($data['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1));
            (new Panel())->page($pageId);
        });
    }

    // Depois das páginas do catálogo: /condominios/{id} não pode capturar /condominios/unidades etc.
    $route->get("/condominios/{id}", "Condominiums:show");
}

unset($erpFrom, $erpTo, $erpCatalog, $erpPageRoutes, $erpRegistries, $erpRegistryPages, $registrySlug, $registry, $base, $erpBases, $erpBase, $erpIndex, $erpName);
$route->namespace(null);
$route->group(null);
