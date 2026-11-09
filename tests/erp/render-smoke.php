<?php

declare(strict_types=1);

/**
 * Smoke de renderização do tema ERP sem banco: monta os dados da moldura pelo próprio
 * Panel::layoutData() e renderiza dashboard, visões gerais, todas as páginas do catálogo,
 * estados e o login (view do Studio). Qualquer aviso PHP vira falha.
 *
 * Uso: VENDOR=/caminho/vendor [SMOKE_HOST=erp.connect.lab] php tests/erp/render-smoke.php [--out=dir]
 * SMOKE_HOST=erp.connect.lab verifica o ERP no host próprio (rotas na raiz).
 * (o vendor fica no checkout principal; não é versionado no worktree).
 */

$root = dirname(__DIR__, 2);
$vendor = getenv('VENDOR') ?: $root . '/vendor';
// Só os namespaces necessários: o autoload do Composer carrega Boot/Settings.php, que exige o banco.
$psr4 = ['Source\\' => $root . '/source/', 'MovesCode\\View\\' => $vendor . '/movescode/view/src/', 'BaconQrCode\\' => $vendor . '/bacon/bacon-qr-code/src/', 'DASPRiD\\Enum\\' => $vendor . '/dasprid/enum/src/'];
spl_autoload_register(static function (string $class) use ($psr4): void {
    foreach ($psr4 as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) require $file;
            return;
        }
    }
});

set_error_handler(static function (int $no, string $msg, string $file, int $line): bool {
    if (!(error_reporting() & $no)) return false;
    throw new ErrorException($msg, 0, $no, $file, $line);
});

$smokeHost = getenv('SMOKE_HOST') ?: 'www.connect.lab';
$_SERVER['HTTP_HOST'] = $smokeHost;
$_SERVER['HTTPS'] = 'on';
$_SERVER['SCRIPT_NAME'] = '/index.php';
foreach ([
    'CONF_VIEW_ERP' => 'connect', 'CONF_VIEW_THEME' => 'default', 'CONF_VIEW_EXT' => 'php', 'CONF_VIEW_PATH' => $root,
    'CONF_URL_LOCAL' => 'https://www.connect.lab', 'CONF_URL_SSL' => true, 'CONF_URL_BASE' => 'https://www.connect.lab', 'CONF_URL_TEST' => 'https://www.connect.lab',
    'CONF_SITE_NAME' => 'Connect', 'CONF_SITE_DESC' => 'Connect', 'CONF_SITE_LANG' => 'pt_BR', 'CONF_SITE_DOMAIN' => 'connect.lab',
    'CONF_SOCIAL_TWITTER_CREATOR' => '', 'CONF_SOCIAL_TWITTER_PUBLISHER' => '', 'CONF_SOCIAL_FACEBOOK_APP' => '', 'CONF_SOCIAL_FACEBOOK_PAGE' => '', 'CONF_SOCIAL_FACEBOOK_AUTHOR' => '',
    'CONF_SES_PATH' => sys_get_temp_dir() . '/erp-smoke-sessions/',
] as $name => $value) {
    if (!defined($name)) define($name, $value);
}
@mkdir(CONF_SES_PATH, 0700, true);
require_once $root . '/source/Boot/Helpers.php';
$erpRoot = 'https://' . $smokeHost . erp_base();
$erpPrefix = erp_base();

$out = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
}
if ($out) @mkdir($out, 0777, true);

putenv('ERP_DEMO_DATA=true');

// Usuário com todas as permissões do ERP; o caso sem permissão é verificado mais abaixo.
$user = new class extends Source\Models\User {
    public array $granted = ['*'];
    public function can(string $permission): bool { return in_array('*', $this->granted, true) || in_array($permission, $this->granted, true); }
};
foreach (['id' => 1, 'first_name' => 'Ana', 'last_name' => 'Exemplo', 'email' => 'ana@example.invalid', 'level' => 5] as $k => $v) {
    $user->$k = $v;
}

$seo = new class { public function render(string $t, string $d, string $u, string $i, bool $f = true): string { return '<title>' . htmlspecialchars($t) . '</title>'; } };
$reflection = new ReflectionClass(Source\Controllers\Erp\Connect\Panel::class);
$panel = $reflection->newInstanceWithoutConstructor();
$set = static function (string $prop, mixed $value) use ($reflection, $panel): void {
    $p = $reflection->getProperty($prop);
    $p->setValue($panel, $value);
};
$theme = $root . '/container/erp/connect/v1/';
$catalog = require $theme . 'components/layout/catalog.php';
$set('catalog', $catalog);
$set('seo', $seo);
// Escopo de condomínios fixo (sem banco) para o seletor da navbar.
$set('condominiumOptions', [['id' => 7, 'name' => 'Residencial Exemplo'], ['id' => 9, 'name' => 'Edifício <Exemplo>']]);
$layoutData = $reflection->getMethod('layoutData');

$render = static function (string $template, array $data) use ($theme): string {
    // Mesmo caminho do controller: Source\Core\View compartilha os dados com a moldura.
    return (new Source\Core\View($theme, 'php'))->render($template, $data);
};

$checks = 0; $failures = [];
$assert = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) $failures[] = $label;
};
$save = static function (string $name, string $html) use ($out): void {
    if ($out) file_put_contents($out . '/' . $name . '.html', $html);
};
$common = static function (string $html, string $label, string $title) use ($assert): void {
    $assert(str_contains($html, '<aside class="erp-sidebar"'), "$label: sidebar");
    $assert(str_contains($html, 'class="erp-navbar"'), "$label: navbar");
    $assert(str_contains($html, 'class="erp-breadcrumbs"'), "$label: breadcrumbs");
    $assert(str_contains($html, 'aria-current="page"'), "$label: item ativo");
    $assert(str_contains($html, htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), "$label: título '$title'");
    $assert(substr_count($html, '<main') === 1, "$label: um <main>");
    $assert((bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), "$label: logout com CSRF");
};

// Dashboard
$data = $layoutData->invoke($panel, $user, 'AP-001', ['dashboard' => Source\Services\Erp\DemoData::get('dashboard')]);
$html = $render('pages/dashboard/index', $data);
$common($html, 'dashboard', 'Dashboard');
$assert(substr_count($html, 'erp-kpi ') >= 4, 'dashboard: 4 indicadores');
$assert(str_contains($html, 'data-erp-condo-switch') && str_contains($html, '<option value="7"') && str_contains($html, 'Edifício &lt;Exemplo&gt;') && str_contains($html, 'Todos os condomínios'), 'navbar: seletor de condomínio com o escopo, escapado');
$assert((bool)preg_match('~action="[^"]*/contexto/condominio"~', $html), 'navbar: seletor envia para /contexto/condominio');
$assert(str_contains($html, 'data-erp-navsearch-toggle') && str_contains($html, 'id="erp-navsearch-panel" role="search" hidden'), 'navbar: busca em ícone, fechada por padrão');
$css = (string)file_get_contents($theme . 'assets/css/erp.css');
$assert(str_contains($css, '.erp-icon-button.erp-navbar__menu { display: none; }'), 'css: hambúrguer escondido no desktop');
$assert(str_contains((string)file_get_contents($theme . 'assets/js/erp.js'), "Acordeão"), 'js: menu em acordeão');
$assert(str_contains($html, 'erp-bars') && str_contains($html, 'erp-donut'), 'dashboard: gráficos');
$assert(str_contains($html, 'data-erp-table'), 'dashboard: tabela paginável');
$assert(str_contains($html, '<dialog class="erp-modal"'), 'dashboard: modal');
$assert(str_contains($html, 'Dados de demonstração'), 'dashboard: aviso de demonstração');
$save('dashboard', $html);

// ADR 0030: com um condomínio na barra superior, os links levam o slug e o dashboard mostra o resumo dele.
Source\Support\Erp\CondominiumContext::use([['id' => 7, 'name' => 'Residencial Exemplo'], ['id' => 9, 'name' => 'Edifício <Exemplo>']], 7);
$data = $layoutData->invoke($panel, $user, 'AP-001', ['dashboard' => Source\Services\Erp\DemoData::get('dashboard'), 'summary' => [['label' => 'Síndico e conselho', 'count' => 4, 'href' => '/cadastros/sindico-e-conselho', 'icon' => 'user']], 'condoTotal' => 2]);
$html = $render('pages/dashboard/index', $data);
$assert(str_contains($html, '<option value="7" selected'), 'contexto: condomínio selecionado na navbar');
$assert(str_contains($html, 'Resumo do Residencial Exemplo'), 'contexto: resumo do condomínio no dashboard');
$assert((bool)preg_match('~href="[^"]*/residencial-exemplo/cadastros/sindico-e-conselho"~', $html), 'contexto: links com o slug do condomínio');
$assert((bool)preg_match('~action="[^"]*/contexto/condominio"~', $html) && !str_contains($html, 'residencial-exemplo/contexto'), 'contexto: troca de condomínio sem slug');
$assert(!str_contains($html, 'dash-condos-title'), 'contexto: tabela de todos os condomínios some com um condomínio escolhido');
$assert(!str_contains($html, 'residencial-exemplo/sair') && !str_contains($html, 'residencial-exemplo/seguranca'), 'contexto: sair e segurança sem slug');
$save('dashboard-condominio', $html);
Source\Support\Erp\CondominiumContext::reset();

// Visões gerais
$pageRoutes = array_column($catalog['pages'], 'route');
foreach ($catalog['groups'] as $key => $group) {
    if ($group['prefix'] === '' || in_array($group['prefix'], $pageRoutes, true)) continue;
    $pages = array_filter($catalog['pages'], static fn (array $p): bool => $p['group'] === $key);
    $data = $layoutData->invoke($panel, $user, null, ['hubGroup' => $key, 'hubPages' => $pages], $group['label']);
    $html = $render('pages/system/hub', $data);
    $common($html, "hub $key", $group['label']);
    foreach ($pages as $p) $assert(str_contains($html, 'href="' . $erpRoot . $p['route'] . '"'), "hub $key: link {$p['route']}");
    $save('hub-' . $key, $html);
}

// Todas as páginas do catálogo, com o template do seu tipo
$hrefs = [];
$kinds = [];
foreach ($catalog['pages'] as $id => $page) {
    if ($id === 'AP-001') continue;
    if ($id === 'CN-001') {
        // Vistoria presencial: páginas próprias (pages/inspections), com dados de exemplo e escape.
        $evil = '<b>Residencial</b>';
        $item = static fn (int $n, string $result, array $photos = []): array => ['id' => $n, 'position' => $n, 'section' => 'Portaria', 'label' => "Item <i>{$n}</i>", 'requires_photo' => $n === 2, 'result' => $result, 'note' => $result === 'issue' ? 'Nota <script>' : null, 'answered_at' => null, 'photos' => $photos];
        $inspection = ['id' => 5, 'condominium_id' => 7, 'condominium' => $evil, 'template_id' => 1, 'template' => 'Ronda semanal', 'plan_id' => 1, 'inspector_id' => 1, 'inspector' => 'Ana Exemplo', 'status' => 'in_progress',
            'checkin' => ['at' => '2026-11-08 09:00:00', 'status' => 'outside', 'latitude' => -22.9, 'longitude' => -47.06, 'accuracy_m' => 12.0, 'distance_m' => 480.0, 'note' => 'GPS <ruim>'],
            'summary' => null, 'completed_at' => null, 'counts' => ['pending' => 1, 'ok' => 1, 'issue' => 1, 'not_applicable' => 0],
            'items' => [$item(1, 'ok'), $item(2, 'issue', [['id' => 3, 'width' => 10, 'height' => 10, 'latitude' => null, 'longitude' => null, 'created_at' => '2026-11-08 09:05:00']]), $item(3, 'pending')],
            'deliveries' => [], 'editable' => true];
        $cnCommon = ['flash' => ['type' => 'success', 'text' => 'Ok <x>'], 'base' => '/operacional/vistorias'];
        $pagesToRender = [
            'index' => ['pages/inspections/index', ['board' => [['plan_id' => 1, 'condominium_id' => 7, 'condominium' => $evil, 'template_id' => 1, 'template' => 'Ronda', 'frequency' => 'weekly', 'last_completed_at' => null, 'next_due_on' => '2026-11-01', 'status' => 'overdue', 'open_inspection_id' => null]],
                'recent' => [['id' => 5, 'condominium_id' => 7, 'condominium' => $evil, 'template' => 'Ronda', 'status' => 'completed', 'inspector' => 'Ana', 'checkin_at' => '2026-11-08 09:00:00', 'checkin_status' => 'outside', 'completed_at' => null, 'items' => 3, 'issues' => 1, 'pending' => 0]],
                'filters' => ['condominium_id' => 0, 'status' => ''], 'condominiums' => [['id' => 7, 'name' => $evil, 'document' => null, 'status' => 'active']], 'canPerform' => true, 'canManage' => true], 'Vistorias'],
            'create' => ['pages/inspections/create', ['condominiums' => [['id' => 7, 'name' => $evil, 'document' => null, 'status' => 'active']], 'templates' => [['id' => 1, 'name' => 'Ronda', 'description' => null, 'frequency' => 'weekly', 'status' => 'active', 'items' => 3]],
                'geolocations' => [7 => ['latitude' => -22.9, 'longitude' => -47.06, 'radius_m' => 150]], 'selected' => ['condominium_id' => 7, 'template_id' => 1], 'old' => [], 'errors' => ['checkin_note' => 'Explique']], 'Nova vistoria'],
            'show' => ['pages/inspections/show', ['inspection' => $inspection, 'errors' => ['item_3' => 'Responda: Item 3'], 'canPerform' => true], $evil],
            'show-done' => ['pages/inspections/show', ['inspection' => ['status' => 'completed', 'editable' => false, 'summary' => 'Resumo <b>'] + $inspection, 'errors' => [], 'canPerform' => true], $evil],
            'settings' => ['pages/inspections/settings', ['templates' => [['id' => 1, 'name' => 'Ronda <x>', 'description' => null, 'frequency' => 'weekly', 'status' => 'active', 'items' => 3]], 'editing' => null, 'editingText' => '',
                'plans' => [['id' => 1, 'condominium_id' => 7, 'condominium' => $evil, 'template_id' => 1, 'template' => 'Ronda', 'frequency' => 'weekly', 'starts_on' => '2026-11-01', 'status' => 'active']],
                'condominiums' => [['id' => 7, 'name' => $evil, 'document' => null, 'status' => 'active']], 'geolocations' => [], 'old' => [], 'errors' => ['items' => 'Informe']], 'Configurar vistorias'],
        ];
        foreach ($pagesToRender as $name => [$template, $pageData, $title]) {
            $html = $render($template, $layoutData->invoke($panel, $user, 'CN-001', $pageData + $cnCommon, $title));
            $assert(str_contains($html, '<aside class="erp-sidebar"') && substr_count($html, '<main') === 1, "CN-001 $name: moldura");
            $assert(!str_contains($html, '<b>Residencial') && !str_contains($html, '<script>') && !str_contains($html, '<i>') && !str_contains($html, 'Ok <x>'), "CN-001 $name: escape");
            $assert(str_contains($html, 'inspections.css') && str_contains($html, 'inspections.js'), "CN-001 $name: assets do módulo");
            $save('cn-001-' . $name, $html);
        }
        $show = $render('pages/inspections/show', $layoutData->invoke($panel, $user, 'CN-001', $pagesToRender['show'][1] + $cnCommon, 'x'));
        $assert(substr_count($show, 'enctype="multipart/form-data"') === 3 && str_contains($show, 'capture="environment"') && str_contains($show, '/operacional/vistorias/5/fotos/3'), 'CN-001: itens com foto pela câmera e foto servida por rota autorizada');
        $assert(substr_count($show, "name='csrf'") >= 6, 'CN-001: formulários com CSRF');
        $done = $render('pages/inspections/show', $layoutData->invoke($panel, $user, 'CN-001', $pagesToRender['show-done'][1] + $cnCommon, 'x'));
        $assert(!str_contains($done, 'multipart/form-data') && str_contains($done, 'id="enviar"'), 'CN-001: concluída fica só leitura e oferece envio');
        continue;
    }
    if ($id === 'CN-004') {
        // Chamados (ADR 0034): abertura para o Help Desk com condomínio, unidade e solicitante.
        $evil = '<b>Residencial</b>';
        $ticket = ['id' => 3, 'protocol' => 'CH261109ABCD', 'subject' => 'Interfone <script>', 'message' => 'x', 'area' => 'general', 'origin' => 'erp', 'category' => null,
            'priority' => 'high', 'status' => 'open', 'condominium' => ['id' => 7, 'name' => $evil], 'unit' => ['id' => 2, 'label' => 'B - 101'],
            'requester' => ['user_id' => null, 'name' => 'Maria', 'email' => 'm@example.com', 'phone' => null], 'assigned_to' => null, 'due_at' => '2026-11-10 10:00:00', 'resolved_at' => null, 'created_at' => '2026-11-09 10:00:00'];
        $html = $render('pages/tickets/index', $layoutData->invoke($panel, $user, 'CN-004', [
            'base' => '/operacional/chamados', 'flash' => ['type' => 'success', 'text' => 'Ok <x>', 'protocol' => 'CH261109ABCD'], 'errors' => ['unit_id' => 'Unidade <i>'],
            'old' => ['condominium_id' => 7, 'unit_id' => 2], 'condominiums' => [['id' => 7, 'name' => $evil]], 'unitsByCondominium' => [7 => [['id' => 2, 'label' => 'B - 101']]],
            'tickets' => [$ticket], 'filters' => ['condominium_id' => 0, 'status' => '', 'q' => ''], 'helpdeskUrl' => 'https://studio.connect.lab/ambiente/helpdesk',
        ]));
        $common($html, $id, 'Chamados');
        $assert(!str_contains($html, '<b>Residencial') && !str_contains($html, 'Interfone <script>') && !str_contains($html, 'Ok <x>') && !str_contains($html, 'Unidade <i>'), 'CN-004: escape');
        $assert(str_contains($html, 'name="condominium_id"') && str_contains($html, 'name="unit_id"') && str_contains($html, 'name="requester_name"') && str_contains($html, '<option value="2" selected>'), 'CN-004: condomínio, unidade e solicitante no formulário');
        $assert(str_contains($html, 'CH261109ABCD') && str_contains($html, 'Unid. B - 101') && str_contains($html, '/ambiente/helpdesk'), 'CN-004: lista com contexto e link do Help Desk');
        $assert(str_contains($html, 'erp-envswitch') && str_contains($html, 'Help Desk') && str_contains($html, 'Studio'), 'sidebar: alternador de ambientes');
        $save('cn-004', $html);
        continue;
    }
    if ($id === 'AP-004') {
        // /erp/condominios usa dados reais (Condominiums + CondominiumScope); aqui, amostra fixa.
        $template = 'pages/condominiums/index';
        $data = $layoutData->invoke($panel, $user, 'AP-004', ['condominiums' => [
            ['id' => 7, 'name' => '<b>Residencial</b> Exemplo', 'document' => '12345678000195', 'status' => 'active'],
            ['id' => 9, 'name' => 'Edifício Exemplo', 'document' => null, 'status' => 'inactive'],
        ]], 'Condomínios');
        $html = $render($template, $data);
        $common($html, $id, 'Condomínios');
        $assert(str_contains($html, '&lt;b&gt;Residencial') && str_contains($html, '12.345.678/0001-95') && str_contains($html, $erpRoot . '/condominios/7'), 'AP-004: dados reais escapados e com link de detalhe');
        $show = $render('pages/condominiums/show', $layoutData->invoke($panel, $user, 'AP-004', ['condominium' => ['id' => 9, 'name' => 'Edifício Exemplo', 'document' => null, 'status' => 'inactive']], 'Edifício Exemplo'));
        $assert(str_contains($show, 'Inativo') && str_contains($show, 'Cadastros do condomínio') && str_contains($show, '<a href="' . $erpRoot . '/condominios">Condomínios</a>'), 'AP-004: detalhe com cadastros vinculados e trilha de volta');
        $user->granted = ['erp.access'];
        $denied = $render('pages/condominiums/index', $layoutData->invoke($panel, $user, 'AP-004', ['condominiums' => []], 'Condomínios'));
        $assert(!str_contains($denied, '<a href="' . $erpRoot . '/condominios"'), 'sem erp.condominiums.view: item some do menu');
        $user->granted = ['*'];
        continue;
    }
    [$template, $data] = $panel->pageView($user, $id);
    $html = $render($template, $data);
    $kinds[$page['kind']] = ($kinds[$page['kind']] ?? 0) + 1;
    $common($html, $id, $page['name']);
    $assert($template === 'pages/modules/' . $page['kind'], "$id: template do tipo {$page['kind']}");
    $assert(!str_contains($html, 'Interface em construção'), "$id: não usa a página genérica");
    $assert(str_contains($html, $page['spec']), "$id: rastreabilidade da ficha");
    preg_match_all('~\sid="([^"]+)"~', $html, $ids);
    $dupes = array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1));
    $assert($dupes === [], "$id: ids duplicados " . implode(',', $dupes));
    $expect = [
        'list' => ['data-erp-table data-per-page', 'role="tablist"', '-delete"', 'class="erp-filters"'],
        'report' => ['data-erp-table data-per-page', 'Parâmetros do relatório', 'Exportar'],
        'form' => ['data-erp-validate', 'data-erp-summary', 'Confirmar'],
        'import' => ['type="file"', 'erp-steps', 'Pré-visualização'],
        'settings' => ['Salvar configurações', 'Histórico de alterações'],
        'document' => ['Emitir documento', 'erp-doc-preview'],
        'chart' => ['erp-bars', '<caption>'],
        'closing' => ['Fechar competência', 'Checklist do fechamento'],
        'dashboard' => ['erp-kpi', 'erp-bars'],
    ][$page['kind']] ?? [];
    foreach ($expect as $needle) $assert(str_contains($html, $needle), "$id ({$page['kind']}): contém $needle");
    if ($page['kind'] === 'report' && str_contains(json_encode($data['spec']['columns']), ':money')) $assert(str_contains($html, '<tfoot>'), "$id: total no rodapé");
    $assert((bool)preg_match('~<a href="' . preg_quote($erpRoot . $page['route'], '~') . '" aria-current="page"~', $html) || in_array($page['group'], ['relatorios', 'operacional', 'configuracoes', 'financeiro'], true), "$id: link ativo na sidebar");
    preg_match_all('~href="' . preg_quote('https://' . $smokeHost, '~') . '(' . preg_quote($erpPrefix, '~') . '/[^"#]*)~', $html, $m);
    foreach ($m[1] as $h) $hrefs[$h] = true;
    $save('page-' . strtolower($id), $html);
}

// Links internos apontam para rotas registradas
$registered = [$erpPrefix . '/seguranca' => true, $erpPrefix . '/dashboard' => true, $erpPrefix . '/configuracoes' => true, $erpPrefix . '/login' => true, $erpPrefix . '/sair' => true];
foreach ($catalog['pages'] as $p) $registered[$erpPrefix . $p['route']] = true;
foreach ($catalog['groups'] as $g) if ($g['prefix'] !== '') $registered[$erpPrefix . $g['prefix']] = true;
$registered['/studio/seguranca/2fa'] = true; // registrada também no host do ERP (index.php)
foreach (array_keys($hrefs) as $h) if (!str_starts_with($h, '/container/')) $assert(isset($registered[$h]), "link sem rota: $h");

// Estados
foreach ([[403, false], [404, true]] as [$code, $nav]) {
    $data = $layoutData->invoke($panel, $user, null, ['stateCode' => $code, 'stateTitle' => "Estado $code", 'stateMessage' => 'Mensagem', 'withNavigation' => $nav], "Estado $code");
    $html = $render('pages/system/state', $data);
    $assert(str_contains($html, "Erro $code"), "estado $code");
    $assert($nav === str_contains($html, 'erp-sidebar"'), "estado $code: navegação " . ($nav ? 'visível' : 'oculta'));
}
// Fechamento mensal (AP-030) com dados reais ($closing de Panel::closingData, ADR 0032): um condomínio
// aberto, fechado e reaberto, sem permissão de fechar, e "Todos" (só consulta).
$closingRow = static fn (array $over = []): array => $over + [
    'condominium_id' => 7, 'condominium_name' => 'Residencial <Exemplo>', 'status' => 'open', 'closed_at' => null, 'reopened_at' => null, 'reopen_reason' => null,
    'revenue_cents' => 9632000, 'expense_cents' => 8894715, 'open_revenue_cents' => 120000, 'open_expense_cents' => 0, 'result_cents' => 737285, 'entries' => 41,
];
$closingPage = static function (?int $condominium, array $rows, bool $manage, string $name = '') use ($panel, $user, $layoutData, $render, $save): string {
    $closing = ['competence' => '2026-10', 'condominium_id' => $condominium, 'rows' => $rows, 'totals' => Source\Services\Erp\MonthClosing::overviewTotals($rows)];
    $html = $render('pages/modules/closing', $layoutData->invoke($panel, $user, 'AP-030', ['closing' => $closing, 'closingCompetence' => '2026-10', 'canManageClosing' => $manage]));
    if ($name !== '') $save('page-ap-030-' . $name, $html);
    return $html;
};
$html = $closingPage(7, [$closingRow()], true, 'open');
$assert(str_contains($html, 'action="' . $erpRoot . '/financeiro/fechamento-mensal/fechar"') && str_contains($html, 'name="competence" value="2026-10"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'data-erp-confirm='), 'AP-030: aberta oferece Fechar (POST com CSRF, competência e confirmação)');
$assert(str_contains($html, 'R$ 96.320,00') && str_contains($html, 'R$ 7.372,85') && str_contains($html, 'Residencial &lt;Exemplo&gt;') && str_contains($html, 'Ainda há lançamentos em aberto') && !str_contains($html, '/reabrir') && !str_contains($html, 'Checklist do fechamento'), 'AP-030: resumo real escapado, sem demonstração e sem Reabrir');
$html = $closingPage(7, [$closingRow(['status' => 'closed', 'closed_at' => '2026-11-02 10:30:00', 'revenue_cents' => 100000, 'expense_cents' => 150000, 'result_cents' => -50000, 'open_revenue_cents' => 0])], true, 'closed');
$assert(str_contains($html, 'Fechada em 02/11/2026 10:30') && str_contains($html, '-R$ 500,00') && str_contains($html, 'action="' . $erpRoot . '/financeiro/fechamento-mensal/reabrir"') && str_contains($html, 'name="reason" minlength="5" maxlength="255" required') && !str_contains($html, '/fechamento-mensal/fechar"'), 'AP-030: fechada oferece Reabrir com motivo de 5 a 255 caracteres');
$html = $closingPage(7, [$closingRow(['status' => 'reopened', 'reopened_at' => '2026-11-03 08:00:00', 'reopen_reason' => 'Energia <errada>'])], true);
$assert(str_contains($html, 'Reaberta em 03/11/2026 08:00') && str_contains($html, 'Energia &lt;errada&gt;') && str_contains($html, '/fechamento-mensal/fechar"'), 'AP-030: reaberta mostra o motivo e permite fechar de novo');
$html = $closingPage(7, [$closingRow()], false);
$assert(str_contains($html, 'R$ 96.320,00') && !str_contains($html, '/fechamento-mensal/fechar"') && !str_contains($html, '/reabrir"'), 'AP-030: sem erp.closing.manage só consulta');
$html = $closingPage(null, [$closingRow(['status' => 'closed']), $closingRow(['condominium_id' => 9, 'condominium_name' => 'Edifício Dois'])], false, 'all');
$assert(str_contains($html, '1 de 2 fechados') && str_contains($html, 'Edifício Dois') && str_contains($html, 'escolha um condomínio na barra superior') && !str_contains($html, 'method="post" action="' . $erpRoot . '/financeiro'), 'AP-030: em Todos lista cada condomínio e pede para escolher um antes de fechar');

// Faturas (AP-032) com dados reais ($invoices de Panel::invoiceData, ADR 0033): lista, ações pela API e estados.
$invoiceRow = static fn (array $over = []): array => $over + [
    'id' => 5, 'condominium_id' => 7, 'condominium_name' => 'Residencial <Exemplo>', 'invoice_type_id' => 2, 'type_name' => 'Taxa <condominial>',
    'competence' => '2026-11', 'due_on' => '2026-11-10', 'status' => 'draft', 'status_label' => 'Em preparação', 'units_count' => 48, 'amount_cents' => 4320000,
    'notes' => null, 'issued_at' => null, 'cancelled_at' => null, 'cancel_reason' => null,
];
$invoicePage = static function (array $rows, bool $manage, string $name = '') use ($panel, $user, $layoutData, $render, $save): string {
    $invoices = ['data' => $rows, 'total' => count($rows), 'page' => 1, 'per_page' => 25, 'pages' => count($rows) > 0 ? 2 : 0];
    $html = $render('pages/finance/invoices', $layoutData->invoke($panel, $user, 'AP-032', ['invoices' => $invoices, 'canManageInvoices' => $manage]));
    if ($name !== '') $save('page-ap-032-' . $name, $html);
    return $html;
};
$html = $invoicePage([$invoiceRow(), $invoiceRow(['id' => 6, 'status' => 'issued', 'status_label' => 'Emitida']), $invoiceRow(['id' => 8, 'status' => 'cancelled', 'status_label' => 'Cancelada', 'cancel_reason' => 'Rateio <revisto>'])], true, 'list');
$common($html, 'AP-032', 'Faturas');
$assert(str_contains($html, 'data-erp-invoices data-api="https://' . $smokeHost . '/api/v1/invoices"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'AP-032: tela real fala com /api/v1/invoices e leva o CSRF');
$assert(str_contains($html, 'Taxa &lt;condominial&gt;') && str_contains($html, 'Rateio &lt;revisto&gt;') && str_contains($html, 'R$ 43.200,00') && str_contains($html, '10/11/2026') && !str_contains($html, 'Interface proposta'), 'AP-032: lista real escapada, sem demonstração');
$assert(substr_count($html, 'data-invoice-issue=') === 1 && substr_count($html, 'data-invoice-cancel=') === 2 && substr_count($html, 'data-invoice-open=') === 3, 'AP-032: emitir só em preparação, cancelar fora das canceladas, itens em todas');
$assert(str_contains($html, 'id="invoice-prepare"') && str_contains($html, 'name="reason" minlength="5" maxlength="255" required') && str_contains($html, 'value="fraction"') && str_contains($html, 'rel="next"'), 'AP-032: preparar, cancelar com motivo, rateio e paginação');
preg_match_all('~\sid="([^"]+)"~', $html, $ids);
$assert(array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1)) === [], 'AP-032: sem ids duplicados');
$html = $invoicePage([$invoiceRow()], false);
$assert(str_contains($html, 'data-invoice-open=') && !str_contains($html, 'data-invoice-issue=') && !str_contains($html, 'data-invoice-cancel=') && !str_contains($html, 'id="invoice-prepare"'), 'AP-032: sem erp.invoices.manage só consulta');
$html = $invoicePage([], true, 'empty');
$assert(str_contains($html, 'Nenhuma fatura encontrada') && str_contains($html, 'Preparar fatura'), 'AP-032: lista vazia orienta a preparar');

// Cobrança bancária (AP-035, AP-036, AP-038; ADR 0035 e 0038) com dados reais, só sandbox.
$chargeRow = static fn (array $over = []): array => $over + [
    'id' => 11, 'condominium_id' => 7, 'bank_account_id' => 3, 'entry_id' => 90, 'adapter' => 'sandbox', 'status' => 'registered', 'status_label' => 'Registrado',
    'our_number' => '0000000001', 'amount_cents' => 90000, 'due_on' => '2026-11-10', 'external_id' => 'SBX-1', 'digitable_line' => 'SANDBOX <0001>', 'barcode' => 'SANDBOX',
    'last_error' => null, 'paid_cents' => null, 'paid_on' => null, 'registered_at' => '2026-11-01 10:00:00', 'cancelled_at' => null,
];
$bankPage = static function (string $id, string $template, array $data, string $name = '') use ($panel, $user, $layoutData, $render, $save): string {
    $html = $render($template, $layoutData->invoke($panel, $user, $id, $data + ['spec' => ['fileLabel' => 'Arquivo de retorno bancário']]));
    if ($name !== '') $save('page-' . strtolower($id) . '-' . $name, $html);
    return $html;
};
$charges = [$chargeRow(), $chargeRow(['id' => 12, 'status' => 'pending', 'status_label' => 'Aguardando banco', 'digitable_line' => null, 'last_error' => 'Sem resposta do <banco>']),
    $chargeRow(['id' => 13, 'status' => 'paid', 'status_label' => 'Pago', 'paid_cents' => 85000, 'paid_on' => '2026-11-09']), $chargeRow(['id' => 14, 'status' => 'cancelled', 'status_label' => 'Cancelado'])];
$html = $bankPage('AP-035', 'pages/finance/bank-charges', ['bankCharges' => ['data' => $charges, 'total' => 4, 'page' => 1, 'per_page' => 25, 'pages' => 2], 'bankReady' => true, 'canManageCharges' => true], 'list');
$common($html, 'AP-035', 'Boletos');
$assert(str_contains($html, 'data-erp-bank-charges data-api="https://' . $smokeHost . '/api/v1/bank-charges"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'Ambiente de testes'), 'AP-035: tela real fala com /api/v1/bank-charges, leva o CSRF e avisa que é sandbox');
$assert(str_contains($html, 'SANDBOX &lt;0001&gt;') && str_contains($html, 'Sem resposta do &lt;banco&gt;') && str_contains($html, 'R$ 900,00') && str_contains($html, 'pago R$ 850,00') && !str_contains($html, 'Interface proposta'), 'AP-035: lista real escapada, pago com valor diferente, sem demonstração');
$assert(substr_count($html, 'data-charge-refresh=') === 2 && substr_count($html, 'data-charge-cancel=') === 2 && substr_count($html, 'data-charge-copy=') === 1 && str_contains($html, 'id="charge-issue"') && str_contains($html, 'rel="next"'), 'AP-035: consultar e cancelar só aguardando ou registrado, copiar a linha, emitir e paginação');
preg_match_all('~\sid="([^"]+)"~', $html, $ids);
$assert(array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1)) === [], 'AP-035: sem ids duplicados');
$html = $bankPage('AP-035', 'pages/finance/bank-charges', ['bankCharges' => ['data' => $charges, 'total' => 4, 'page' => 1, 'per_page' => 25, 'pages' => 1], 'bankReady' => true, 'canManageCharges' => false]);
$assert(str_contains($html, 'data-charge-copy=') && !str_contains($html, 'data-charge-refresh=') && !str_contains($html, 'data-charge-cancel=') && !str_contains($html, 'id="charge-issue"'), 'AP-035: sem erp.bank_charges.manage só consulta');
$html = $bankPage('AP-035', 'pages/finance/bank-charges', ['bankCharges' => ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0], 'bankReady' => true, 'canManageCharges' => true], 'empty');
$assert(str_contains($html, 'Nenhum boleto encontrado') && str_contains($html, 'Emitir boleto'), 'AP-035: lista vazia orienta a emitir');

$html = $bankPage('AP-036', 'pages/finance/bank-return-import', ['bankReady' => true, 'canManageCharges' => true], 'form');
$common($html, 'AP-036', 'Arquivo de Retorno');
$assert(str_contains($html, 'data-erp-bank-return data-api="https://' . $smokeHost . '/api/v1/bank-returns"') && str_contains($html, 'type="file" id="ret-file" name="file" accept=".ret,.txt" required') && str_contains($html, 'data-return-accounts') && str_contains($html, 'SANDBOX-RETORNO;v1'), 'AP-036: importa pela API com conta bancária e arquivo, avisa o formato do sandbox');
$html = $bankPage('AP-036', 'pages/finance/bank-return-import', ['bankReady' => true, 'canManageCharges' => false]);
$assert(!str_contains($html, 'data-bank-return-form') && str_contains($html, 'Importar exige permissão'), 'AP-036: sem erp.bank_charges.manage não oferece importar');

$event = static fn (array $over = []): array => $over + ['id' => 31, 'condominium_id' => 7, 'bank_account_id' => 3, 'return_file_id' => 2, 'charge_id' => 11, 'event_key' => 'E1', 'kind' => 'paid',
    'our_number' => '0000000001', 'amount_cents' => 85000, 'occurred_on' => '2026-11-09', 'outcome' => 'divergence', 'outcome_label' => 'Divergência', 'note' => 'Valor pago (85000) diferente do título <90000>.', 'resolved_at' => null];
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [$event(), $event(['id' => 32, 'charge_id' => null, 'outcome' => 'unknown_charge', 'outcome_label' => 'Título não encontrado', 'our_number' => '0000000099'])], 'bankReady' => true, 'canManageCharges' => true], 'list');
$common($html, 'AP-038', 'Retornos');
$assert(str_contains($html, 'data-erp-bank-divergences data-api="https://' . $smokeHost . '/api/v1/bank-events"') && substr_count($html, 'data-divergence-resolve=') === 2 && str_contains($html, 'name="note" minlength="5" maxlength="255" required'), 'AP-038: fila de divergências com "revisada" e nota de 5 a 255');
$assert(str_contains($html, 'título &lt;90000&gt;') && str_contains($html, 'Título não encontrado') && str_contains($html, 'R$ 850,00'), 'AP-038: motivo escapado e título desconhecido');
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [$event()], 'bankReady' => true, 'canManageCharges' => false]);
$assert(!str_contains($html, 'data-divergence-resolve=') && !str_contains($html, 'id="divergence-resolve"'), 'AP-038: sem erp.bank_charges.manage só consulta');
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [], 'bankReady' => true, 'canManageCharges' => true], 'empty');
$assert(str_contains($html, 'Nenhuma divergência pendente'), 'AP-038: fila vazia');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-035', ['bankCharges' => null]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-036', ['bankReady' => false]) === null
    && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-038', ['bankDivergences' => []]) === 'pages/finance/bank-divergences', 'cobrança: sem dados segue a demonstração; com dados, tela própria');

// Relatórios financeiros (AP-079 balancete, AP-051 demonstrativo; ADR 0037) com dados reais.
$reportPage = static function (string $id, string $template, array $data, string $name = '') use ($panel, $user, $layoutData, $render, $save): string {
    $html = $render($template, $layoutData->invoke($panel, $user, $id, $data + ['reportError' => null]));
    if ($name !== '') $save('page-' . strtolower($id) . '-' . $name, $html);
    return $html;
};
$tbRows = Source\Services\Erp\FinancialReports::rollup([
    ['code' => '1.1.1', 'name' => 'Taxa <condominial>', 'nature' => 'receita', 'planned_cents' => 4320000, 'realized_cents' => 4000000, 'open_cents' => 320000],
    ['code' => '2.1.1', 'name' => 'Energia', 'nature' => 'despesa', 'planned_cents' => 500000, 'realized_cents' => 500000, 'open_cents' => 0],
], ['1' => 'Receitas', '1.1' => 'Ordinárias', '2' => 'Despesas', '2.1' => 'Consumo']);
$tbTotals = Source\Services\Erp\FinancialReports::totals([['nature' => 'receita', 'planned_cents' => 4320000, 'realized_cents' => 4000000, 'open_cents' => 320000], ['nature' => 'despesa', 'planned_cents' => 500000, 'realized_cents' => 500000, 'open_cents' => 0]]);
$html = $reportPage('AP-079', 'pages/reports/trial-balance', ['trialBalance' => ['from' => '2026-10', 'to' => '2026-11', 'rows' => $tbRows, 'totals' => $tbTotals], 'reportPeriod' => ['from' => '2026-10', 'to' => '2026-11']], 'real');
$common($html, 'AP-079', 'Balancete');
$assert(str_contains($html, 'Taxa &lt;condominial&gt;') && str_contains($html, 'Ordinárias') && str_contains($html, 'R$ 43.200,00') && str_contains($html, 'R$ 35.000,00') && str_contains($html, 'name="de" value="2026-10"') && !str_contains($html, 'Interface proposta'), 'AP-079: balancete real escapado, sintéticas, resultado e período, sem demonstração');
$html = $reportPage('AP-079', 'pages/reports/trial-balance', ['trialBalance' => null, 'reportError' => 'Intervalo máximo de 24 competências.', 'reportPeriod' => ['from' => '2020-01', 'to' => '2026-11']]);
$assert(str_contains($html, 'Intervalo máximo de 24 competências.') && str_contains($html, 'erp-alert--danger'), 'AP-079: período inválido mostra o erro');
$stTotals = Source\Services\Erp\FinancialReports::reconcile(4000000, 500000, 3400000);
$html = $reportPage('AP-051', 'pages/reports/statement', ['statement' => ['from' => '2026-11-01', 'to' => '2026-11-30',
    'accounts' => [['code' => '1.1.1', 'name' => 'Taxa <condominial>', 'nature' => 'receita', 'total_cents' => 4000000], ['code' => '2.1.1', 'name' => 'Energia', 'nature' => 'despesa', 'total_cents' => 500000]],
    'banks' => [['id' => 3, 'condominium_id' => 7, 'condominium_name' => 'Residencial <Exemplo>', 'name' => 'Conta movimento'] + Source\Services\Erp\FinancialReports::bankMovement(1000000, 3900000, 500000)],
    'totals' => $stTotals], 'reportPeriod' => ['from' => '2026-11-01', 'to' => '2026-11-30']], 'real');
$common($html, 'AP-051', 'Demonstrativo');
$assert(str_contains($html, 'Diferença de R$ 1.000,00') && str_contains($html, 'R$ 44.000,00') && str_contains($html, 'Residencial &lt;Exemplo&gt;') && str_contains($html, 'name="de" value="2026-11-01"') && !str_contains($html, 'Interface proposta'), 'AP-051: demonstrativo real com diferença exposta, saldo final e período');
$html = $reportPage('AP-051', 'pages/reports/statement', ['statement' => ['accounts' => [], 'banks' => [], 'totals' => Source\Services\Erp\FinancialReports::reconcile(0, 0, 0)], 'reportPeriod' => ['from' => '2026-11-01', 'to' => '2026-11-09']], 'empty');
$assert(str_contains($html, 'Conciliado') && str_contains($html, 'Nada recebido nem pago'), 'AP-051: período vazio conciliado');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-079', ['trialBalance' => null, 'reportError' => null]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-051', ['statement' => null, 'reportError' => 'x']) === 'pages/reports/statement', 'relatórios: sem permissão segue a demonstração; erro de período usa a tela real');

// Segurança da conta (2FA no próprio ERP): estados ativa com códigos novos, configuração e servidor sem chave.
$security = static fn (array $state): string => $render('pages/auth/security', $layoutData->invoke($panel, $user, null, $state + ['twoFactorEnabled' => false, 'twoFactorConfigured' => true, 'twoFactorEnforced' => false, 'twoFactorSetup' => null, 'recoveryCodes' => [], 'recoveryRemaining' => 0], 'Segurança da conta'));
$html = $security(['twoFactorEnabled' => true, 'recoveryCodes' => ['AAAA-BBBB', '<b>x</b>'], 'recoveryRemaining' => 8]);
$assert(str_contains($html, 'AAAA-BBBB') && str_contains($html, '&lt;b&gt;x&lt;/b&gt;') && str_contains($html, 'value="recovery"') && str_contains($html, 'value="disable"'), 'segurança: 2FA ativo, códigos escapados, renovar e desativar');
$assert(!str_contains($security(['twoFactorEnabled' => true, 'twoFactorEnforced' => true]), 'value="disable"'), 'segurança: 2FA obrigatório não oferece desativar');
$html = $security(['twoFactorSetup' => ['secret' => 'JBSWY3DPEHPK3PXP', 'uri' => 'otpauth://totp/Connect%20ERP:ana?secret=JBSWY3DPEHPK3PXP']]);
$assert(str_contains($html, 'JBSW Y3DP EHPK 3PXP') && str_contains($html, 'value="confirm"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'segurança: configuração com chave, código e CSRF');
$uri = 'otpauth://totp/Connect%20ERP:ana?secret=JBSWY3DPEHPK3PXP';
$html = $security(['twoFactorSetup' => ['secret' => 'JBSWY3DPEHPK3PXP', 'uri' => $uri, 'qr' => Source\Support\QrCode::svg($uri, 196, 'QR code para o aplicativo autenticador')]]);
$assert(str_contains($html, '<figure class="erp-qr"><svg role="img" aria-label="QR code para o aplicativo autenticador"') && !str_contains($html, '<?xml'), 'segurança: QR code do 2FA gerado no servidor, em SVG embutido');
$assert(!str_contains($security(['twoFactorSetup' => ['secret' => 'JBSWY3DPEHPK3PXP', 'uri' => $uri, 'qr' => null]]), 'erp-qr'), 'segurança: sem a biblioteca de QR a tela segue só com a chave');
$assert(str_contains($security(['twoFactorConfigured' => false]), 'CONNECT_2FA_KEY'), 'segurança: servidor sem chave avisa o administrador');

// Demonstração desligada: dashboard mostra estado vazio
putenv('ERP_DEMO_DATA=false');
$data = $layoutData->invoke($panel, $user, 'AP-001', ['dashboard' => Source\Services\Erp\DemoData::get('dashboard')]);
$html = $render('pages/dashboard/index', $data);
$assert(str_contains($html, 'Indicadores ainda indisponíveis') && !str_contains($html, 'R$ 1.284.530'), 'demo off: estado vazio sem números');
[$template, $data] = $panel->pageView($user, 'AP-022');
$html = $render($template, $data);
$assert(str_contains($html, 'Nenhum registro ainda') && !str_contains($html, 'data-erp-table data-per-page'), 'demo off: listagem sem linhas fictícias');
putenv('ERP_DEMO_DATA=true');

// Login (view do Studio reaproveitada)
$studio = static fn (): Source\Core\View => new Source\Core\View($root . '/container/cms/studio/v1/', 'php');
$html = $studio()->render('components/login/login', ['head' => '<title>Entrar</title>', 'cookie' => null, 'adminBase' => $erpPrefix, 'loginEnvironment' => 'erp', 'forgotUrl' => erp_url('/recuperar-senha')]);
$assert(str_contains($html, 'action="' . $erpRoot . '/login"'), 'login: envia para o login do ERP');
$assert(str_contains($html, 'CONNECT ERP') && str_contains($html, 'Esqueci minha senha'), 'login: identidade ERP e recuperação');
$assert((bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'type="password"'), 'login: CSRF e senha');
$save('login', $html);
$html = $studio()->render('components/login/login', ['head' => '', 'cookie' => null, 'adminBase' => '/studio', 'loginEnvironment' => 'studio']);
$assert(str_contains($html, 'MOVESOS') && str_contains($html, 'Área restrita'), 'login do Studio inalterado');

// Registro das rotas (sem despachar): grupo certo para o host e nenhuma rota repetida.
$route = new class {
    public array $paths = [];
    private string $group = '';
    public function __call(string $name, array $args): void
    {
        if ($name === 'group') { $this->group = (string)($args[0] ?? ''); return; }
        if (in_array($name, ['get', 'post'], true)) { $this->paths[] = strtoupper($name) . ' ' . ($this->group . ($args[0] === '/' && $this->group !== '' ? '' : $args[0])); }
    }
};
require $theme . 'routes.php';
$dupes = array_keys(array_filter(array_count_values($route->paths), static fn (int $n): bool => $n > 1));
$assert($dupes === [], 'rotas repetidas: ' . implode(', ', $dupes));
$expected = erp_host() ? ['GET /', 'GET /dashboard', 'GET /login', 'POST /sair', 'GET /condominios/{id}', 'GET /financeiro/contas-a-pagar', 'GET /erp/dashboard', 'GET /erp/login'] : ['GET /erp', 'GET /erp/dashboard', 'GET /erp/login', 'GET /erp/condominios/{id}', 'GET /erp/financeiro/contas-a-pagar'];
foreach ($expected as $path) $assert(in_array($path, $route->paths, true), "rota registrada: $path");
if (!erp_host()) $assert(!in_array('GET /dashboard', $route->paths, true), 'fora do host do ERP não há rotas na raiz');
$idShow = array_search((erp_host() ? '' : '/erp') . '/condominios/{id}', array_map(static fn (string $p): string => substr($p, 4), $route->paths), true);
$idUnits = array_search((erp_host() ? '' : '/erp') . '/condominios/unidades', array_map(static fn (string $p): string => substr($p, 4), $route->paths), true);
$assert($idShow !== false && $idUnits !== false && $idUnits < $idShow, '/condominios/{id} registrada depois de /condominios/unidades');
echo 'Rotas registradas: ' . count($route->paths) . "\n";

ksort($kinds);
echo "Páginas por tipo: " . json_encode($kinds, JSON_UNESCAPED_UNICODE) . "\n";
printf("%d verificações, %d falhas\n", $checks, count($failures));
foreach ($failures as $f) echo "FALHA: $f\n";
exit($failures ? 1 : 0);
