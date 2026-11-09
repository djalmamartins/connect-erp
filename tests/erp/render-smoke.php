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
    if ($id === 'CN-005') {
        // Fila de avisos (ADR 0055 e 0072): situação, trilha mascarada e reenviar.
        $notice = static fn (array $over = []): array => $over + ['id' => 11, 'condominium_id' => 7, 'event_type' => 'waitlist.called', 'status' => 'failed', 'delivery_mode' => null,
            'recipients_count' => 0, 'attempts' => 5, 'available_at' => '2026-11-09 10:00:00', 'processed_at' => '2026-11-09 10:05:00', 'last_error' => 'SMTP <recusou>', 'created_at' => '2026-11-09 09:00:00'];
        $queueData = static fn (array $over = []): array => $over + ['queue' => ['data' => [$notice(), $notice(['id' => 12, 'status' => 'sent', 'delivery_mode' => 'sandbox', 'recipients_count' => 1, 'attempts' => 1, 'last_error' => null]),
            $notice(['id' => 13, 'status' => 'pending', 'attempts' => 2, 'last_error' => null])], 'total' => 3],
            'queueStatus' => null, 'queueMode' => 'sandbox', 'queueDetail' => null, 'queueError' => null, 'canRetry' => true, 'flash' => null];
        $detail = Source\Controllers\Erp\Connect\NotificationQueue::maskDetail($notice(['id' => 12, 'status' => 'sent', 'delivery_mode' => 'sandbox', 'recipients_count' => 1, 'attempts' => 1, 'last_error' => null,
            'deliveries' => [['delivery_mode' => 'sandbox', 'recipient_masked' => 'a***@exemplo.com', 'sent_to' => 'caixa.teste@connect.lab', 'mail_queue_id' => 99, 'created_at' => '2026-11-09 10:05:00']]]));
        $html = $render('pages/notifications/index', $layoutData->invoke($panel, $user, 'CN-005', $queueData(['queueDetail' => $detail])));
        $common($html, $id, 'Fila de avisos');
        $save('cn-005', $html);
        $assert(str_contains($html, 'Lista de espera chamada') && str_contains($html, 'Falhou') && str_contains($html, 'Enviado') && str_contains($html, 'Pendente') && str_contains($html, 'SMTP &lt;recusou&gt;')
            && substr_count($html, '/reenviar"') === 1 && str_contains($html, '/configuracoes/fila-de-avisos/11/reenviar') && str_contains($html, 'Caixa de teste') && str_contains($html, 'data-queue-detail'), 'CN-005: lista com situação e reenviar só no que falhou');
        $assert(str_contains($html, 'a***@exemplo.com') && str_contains($html, 'c******@connect.lab') && !str_contains($html, 'caixa.teste@') && str_contains($html, 'fila de e-mail nº 99'), 'CN-005: trilha com endereços mascarados');
        $html = $render('pages/notifications/index', $layoutData->invoke($panel, $user, 'CN-005', $queueData(['canRetry' => false, 'queueMode' => 'log'])));
        $assert(!str_contains($html, '/reenviar') && str_contains($html, 'Nenhum e-mail sai'), 'CN-005: sem manage não reenvia; modo log');
        $html = $render('pages/notifications/index', $layoutData->invoke($panel, $user, 'CN-005', $queueData(['queue' => ['data' => [], 'total' => 0], 'queueStatus' => 'failed'])));
        $assert(str_contains($html, 'Nenhum aviso nesta situação') && str_contains($html, 'aria-current="page">Falhou'), 'CN-005: filtro sem avisos');
        $html = $render('pages/notifications/index', $layoutData->invoke($panel, $user, 'CN-005', $queueData(['queue' => null])));
        $assert(str_contains($html, 'Fila indisponível'), 'CN-005: sem banco');
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
// Alternador de ambiente (ADR 0034): /ambiente/{environment} vale em qualquer host, sem o prefixo do ERP (index.php).
foreach (Source\Services\Environments::keys() as $environment) $registered['/ambiente/' . $environment] = true;
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
$returnFile = ['id' => 2, 'condominium_id' => 7, 'bank_account_id' => 3, 'adapter' => 'sandbox', 'filename' => 'retorno <nov>.txt', 'sha256' => 'x', 'events_count' => 3, 'applied_count' => 1,
    'divergence_count' => 1, 'other_count' => 1, 'total_cents' => 175000, 'created_at' => '2026-11-09 10:30:00', 'bank_account_name' => 'Conta movimento', 'condominium_name' => 'Residencial Exemplo'];
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [$event(), $event(['id' => 32, 'charge_id' => null, 'outcome' => 'unknown_charge', 'outcome_label' => 'Título não encontrado', 'our_number' => '0000000099'])],
    'bankReturnFiles' => ['data' => [$returnFile, ['divergence_count' => 0, 'other_count' => 0, 'id' => 3] + $returnFile], 'total' => 30, 'page' => 1, 'per_page' => 25, 'pages' => 2], 'bankReady' => true, 'canManageCharges' => true], 'list');
$assert(str_contains($html, 'retorno &lt;nov&gt;.txt') && str_contains($html, '1 divergência(s)') && str_contains($html, '>Conciliado<') && str_contains($html, '1 repetida(s)') && str_contains($html, '09/11/2026 10:30') && str_contains($html, 'rel="next"'), 'AP-038: arquivos importados com conciliação e paginação');
$common($html, 'AP-038', 'Retornos');
$assert(str_contains($html, 'data-erp-bank-divergences data-api="https://' . $smokeHost . '/api/v1/bank-events"') && substr_count($html, 'data-divergence-resolve=') === 2 && str_contains($html, 'name="note" minlength="5" maxlength="255" required'), 'AP-038: fila de divergências com "revisada" e nota de 5 a 255');
$assert(str_contains($html, 'título &lt;90000&gt;') && str_contains($html, 'Título não encontrado') && str_contains($html, 'R$ 850,00'), 'AP-038: motivo escapado e título desconhecido');
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [$event()], 'bankReady' => true, 'canManageCharges' => false]);
$assert(!str_contains($html, 'data-divergence-resolve=') && !str_contains($html, 'id="divergence-resolve"'), 'AP-038: sem erp.bank_charges.manage só consulta');
$html = $bankPage('AP-038', 'pages/finance/bank-divergences', ['bankDivergences' => [], 'bankReturnFiles' => ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0], 'bankReady' => true, 'canManageCharges' => true], 'empty');
$assert(str_contains($html, 'Nenhuma divergência pendente') && str_contains($html, 'Nenhum arquivo de retorno importado'), 'AP-038: fila e arquivos vazios');

// Inadimplência e acordos (AP-033, AP-034; ADR 0039 e 0041) com dados reais.
$agreementRow = static fn (array $over = []): array => $over + ['id' => 4, 'condominium_id' => 7, 'condominium_name' => 'Residencial Exemplo', 'unit_id' => 12, 'unit_label' => 'Apto <101>', 'status' => 'active',
    'chart_account_id' => 5, 'original_cents' => 180000, 'fees_cents' => 3600, 'discount_cents' => 0, 'total_cents' => 183600, 'installments' => 3, 'first_due_on' => '2026-11-20', 'notes' => null,
    'cancelled_at' => null, 'cancel_reason' => null, 'created_at' => '2026-11-09 09:00:00', 'paid_installments' => 0, 'paid_cents' => 0, 'open_cents' => 183600, 'settled' => false];
$delinquency = ['as_of' => '2026-11-09', 'units' => [
    ['condominium_id' => 7, 'condominium_name' => 'Residencial Exemplo', 'unit_id' => 12, 'unit_label' => 'Apto <101>', 'amount_cents' => 180000, 'oldest_due_on' => '2026-07-10', 'max_days_late' => 122,
        'entries' => [['id' => 90, 'description' => 'Taxa <julho>', 'competence' => '2026-07', 'due_on' => '2026-07-10', 'amount_cents' => 90000, 'days_late' => 122, 'bucket' => '90+'], ['id' => 91, 'description' => 'Taxa agosto', 'competence' => '2026-08', 'due_on' => '2026-08-10', 'amount_cents' => 90000, 'days_late' => 91, 'bucket' => '90+']]],
    ['condominium_id' => 7, 'condominium_name' => 'Residencial Exemplo', 'unit_id' => null, 'unit_label' => 'Sem unidade', 'amount_cents' => 5000, 'oldest_due_on' => '2026-11-01', 'max_days_late' => 8, 'entries' => [['id' => 95, 'description' => 'Avulso', 'competence' => '2026-11', 'due_on' => '2026-11-01', 'amount_cents' => 5000, 'days_late' => 8, 'bucket' => '1-30']]],
], 'totals' => ['units' => 2, 'entries' => 3, 'amount_cents' => 185000, 'charges_cents' => 9300, 'total_cents' => 194300, 'missing_index' => ['2026-10'], 'buckets' => ['1-30' => 5000, '31-60' => 0, '61-90' => 0, '90+' => 180000]]];
$delinquency['units'][0] += ['charges_cents' => 9200, 'total_cents' => 189200];
$delinquency['units'][1] += ['charges_cents' => 100, 'total_cents' => 5100];
$agreementPage = static function (string $id, string $template, array $data, string $name = '') use ($panel, $user, $layoutData, $render, $save): string {
    $html = $render($template, $layoutData->invoke($panel, $user, $id, $data));
    if ($name !== '') $save('page-' . strtolower($id) . '-' . $name, $html);
    return $html;
};
$agreementList = ['data' => [$agreementRow(), $agreementRow(['id' => 5, 'status' => 'cancelled', 'cancel_reason' => 'Desistiu <do acordo>']), $agreementRow(['id' => 6, 'paid_installments' => 3, 'settled' => true]),
    $agreementRow(['id' => 7, 'paid_installments' => 1, 'breach' => ['due' => true, 'overdue_installments' => 2, 'max_days_late' => 40, 'reasons' => ['2 parcelas vencidas em aberto']]]),
    $agreementRow(['id' => 8, 'status' => 'breached', 'breach_reason' => 'Parou de <pagar>'])], 'total' => 5, 'page' => 1, 'per_page' => 25, 'pages' => 1];
$html = $agreementPage('AP-033', 'pages/finance/agreements', ['delinquency' => $delinquency, 'agreements' => $agreementList, 'canManageAgreements' => true], 'list');
$common($html, 'AP-033', 'Acordos');
$assert(str_contains($html, 'data-erp-agreements data-api="https://' . $smokeHost . '/api/v1/agreements"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'R$ 1.850,00') && str_contains($html, '122 dias') && !str_contains($html, 'Interface proposta'), 'AP-033: inadimplência real com faixas, API e CSRF');
$assert(substr_count($html, 'data-agreement-unit=') === 1 && str_contains($html, 'Sem unidade') && str_contains($html, 'Taxa &lt;julho&gt;') && !str_contains($html, 'Taxa <julho>'), 'AP-033: fazer acordo só em unidade identificada, dados escapados');
$assert(substr_count($html, 'data-agreement-breach=') === 1 && str_contains($html, '2 parcelas vencidas em aberto') && str_contains($html, 'Quebrado') && str_contains($html, 'Parou de &lt;pagar&gt;') && str_contains($html, 'id="agreement-breach"')
    && str_contains($html, 'R$ 1.892,00') && str_contains($html, '+ R$ 92,00 de encargos') && str_contains($html, 'Faltam meses do IPCA') && str_contains($html, '10/2026'), 'AP-033: encargos calculados, aviso de IPCA e quebra só quando a regra indica');
$assert(substr_count($html, 'data-agreement-cancel=') === 1 && substr_count($html, 'data-agreement-open=') === 5 && str_contains($html, 'Desistiu &lt;do acordo&gt;') && str_contains($html, 'Quitado') && str_contains($html, 'id="agreement-create"') && str_contains($html, 'name="reason" minlength="5" maxlength="255" required'), 'AP-033: cancelar só ativo sem parcela paga, ver todos, quitado e cancelado');
preg_match_all('~\sid="([^"]+)"~', $html, $ids);
$assert(array_keys(array_filter(array_count_values($ids[1]), static fn (int $n): bool => $n > 1)) === [], 'AP-033: sem ids duplicados');
$html = $agreementPage('AP-033', 'pages/finance/agreements', ['delinquency' => $delinquency, 'agreements' => $agreementList, 'canManageAgreements' => false]);
$assert(!str_contains($html, 'data-agreement-breach=') && !str_contains($html, 'data-agreement-unit=') && !str_contains($html, 'data-agreement-cancel=') && !str_contains($html, 'id="agreement-create"') && str_contains($html, 'data-agreement-open='), 'AP-033: sem erp.agreements.manage só consulta');
$html = $agreementPage('AP-033', 'pages/finance/agreements', ['delinquency' => ['as_of' => '2026-11-09', 'units' => [], 'totals' => ['units' => 0, 'entries' => 0, 'amount_cents' => 0, 'buckets' => []]], 'agreements' => ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 25, 'pages' => 0], 'canManageAgreements' => true], 'empty');
$assert(str_contains($html, 'Nenhuma conta vencida') && str_contains($html, 'Nenhum acordo'), 'AP-033: sem inadimplência nem acordos');
$parcels = Source\Controllers\Erp\Connect\Panel::flattenParcels([$agreementRow() + ['parcels' => [
    ['number' => 1, 'entry_id' => 100, 'description' => 'p1', 'competence' => '2026-10', 'due_on' => '2026-10-20', 'amount_cents' => 61200, 'paid_on' => '2026-10-19', 'status' => 'active'],
    ['number' => 2, 'entry_id' => 101, 'description' => 'p2', 'competence' => '2026-11', 'due_on' => '2026-11-05', 'amount_cents' => 61200, 'paid_on' => null, 'status' => 'active'],
    ['number' => 3, 'entry_id' => 102, 'description' => 'p3', 'competence' => '2026-12', 'due_on' => '2026-12-05', 'amount_cents' => 61200, 'paid_on' => null, 'status' => 'active'],
]]], '2026-11-09');
$assert(array_column($parcels, 'state') === ['late', 'open', 'paid'], 'AP-034: parcelas vencidas, a vencer e pagas, nesta ordem');
$html = $agreementPage('AP-034', 'pages/finance/agreement-parcels', ['agreements' => ['data' => [$agreementRow()], 'total' => 1, 'page' => 1, 'per_page' => 25, 'pages' => 1], 'agreementParcels' => $parcels, 'canManageAgreements' => true], 'list');
$common($html, 'AP-034', 'Parcelas de Acordo');
$assert(str_contains($html, 'Vencida') && str_contains($html, 'A vencer') && str_contains($html, 'paga 19/10/2026') && str_contains($html, '2/3') && str_contains($html, 'Apto &lt;101&gt;') && !str_contains($html, 'Interface proposta'), 'AP-034: parcelas reais com situação');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-033', ['delinquency' => null]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-034', ['agreementParcels' => []]) === 'pages/finance/agreement-parcels', 'acordos: sem dados segue a demonstração');
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

// Regra de cobrança e índices (AP-165, ADR 0040 e 0043) com dados reais.
$billingRule = Source\Services\Erp\LateCharges::DEFAULT_RULE + ['condominium_id' => null, 'source' => 'default'];
$html = $agreementPage('AP-165', 'pages/finance/billing-rules', ['billingRule' => $billingRule, 'priceIndexes' => [['competence' => '2026-10', 'rate_percent' => 0.44], ['competence' => '2026-09', 'rate_percent' => -0.1]], 'canManageBilling' => true], 'manage');
$common($html, 'AP-165', 'Índices');
$assert(str_contains($html, 'data-erp-billing data-rules-api="https://' . $smokeHost . '/api/v1/billing-rules"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'value="2.00"') && str_contains($html, 'value="1.00"') && str_contains($html, 'Padrão da Connect') && str_contains($html, '0,44%') && str_contains($html, '-0,10%') && str_contains($html, 'data-index-import') && str_contains($html, 'Salvar regra'), 'AP-165: regra padrão, índices e formulários para quem gerencia');
$html = $agreementPage('AP-165', 'pages/finance/billing-rules', ['billingRule' => $billingRule, 'priceIndexes' => [], 'canManageBilling' => false]);
$assert(!str_contains($html, 'Salvar regra') && !str_contains($html, 'data-index-form') && str_contains($html, 'disabled') && str_contains($html, 'Nenhum mês do IPCA lançado'), 'AP-165: sem erp.billing_rules.manage só consulta');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-165', ['billingRule' => null]) === null, 'AP-165: sem permissão segue a demonstração');

// Documentos da unidade (AP-123/124/125/130, ADR 0044 e 0046) com dados reais.
$docRow = static fn (array $over = []): array => $over + ['id' => 31, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 9, 'unit_label' => 'Apto <101>',
    'kind' => 'debt_certificate', 'kind_label' => 'Certidão de Débitos', 'as_of' => '2026-11-09', 'reference' => null, 'result' => 'debts', 'total_cents' => 52345,
    'verification_code' => 'ABCDEFGHJKMNPQRS', 'verification_display' => 'ABCD-EFGH-JKMN-PQRS', 'status' => 'issued', 'issued_at' => '2026-11-09 10:00:00', 'cancelled_at' => null, 'cancel_reason' => null];
$docUnits = [['id' => 9, 'condominium_id' => 1, 'label' => 'Apto <101>', 'condominium_name' => 'Residencial Aurora'], ['id' => 10, 'condominium_id' => 2, 'label' => 'Casa 2', 'condominium_name' => 'Vila Sol']];
$docList = ['data' => [$docRow(), $docRow(['id' => 32, 'result' => 'clear', 'total_cents' => 0, 'status' => 'cancelled', 'cancelled_at' => '2026-11-09 11:00:00', 'cancel_reason' => 'Data errada'])], 'total' => 2, 'page' => 1, 'per_page' => 25, 'pages' => 1];
$docData = static fn (string $kind, array $over = []): array => $over + ['documentKind' => $kind, 'unitDocuments' => $docList, 'unitDocument' => null, 'documentUnits' => $docUnits, 'documentError' => null, 'canManageDocuments' => true];
foreach (Source\Controllers\Erp\Connect\Panel::UNIT_DOCUMENT_PAGES as $docPage => $docKind) {
    $html = $agreementPage($docPage, 'pages/documents/unit-document', $docData($docKind), 'list');
    $common($html, $docPage, 'Emitir');
    $assert(str_contains($html, 'data-erp-unit-documents data-api="https://' . $smokeHost . '/api/v1/unit-documents" data-kind="' . $docKind . '"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html)
        && str_contains($html, '<optgroup label="Vila Sol">') && str_contains($html, 'Apto &lt;101&gt;') && str_contains($html, 'ABCD-EFGH-JKMN-PQRS') && str_contains($html, 'data-unit-document-verify')
        && substr_count($html, 'data-unit-document-cancel="') === 1 && str_contains($html, '?documento=31'), "{$docPage}: emitir, conferir, lista com código e cancelar só o emitido");
    $assert(str_contains($html, 'name="until_competence"') === ($docKind === 'quittance') && str_contains($html, 'name="purpose"') === ($docKind === 'consent_letter'), "{$docPage}: campos próprios do tipo");
}
$html = $agreementPage('AP-123', 'pages/documents/unit-document', $docData('debt_certificate', ['canManageDocuments' => false]));
$assert(!str_contains($html, 'data-unit-document-form') && !str_contains($html, 'data-unit-document-cancel') && str_contains($html, 'data-unit-document-verify'), 'AP-123: sem erp.unit_documents.manage só consulta e confere');
$docContent = ['overdue' => [['entry_id' => 1, 'description' => 'Taxa <out>', 'competence' => '2026-09', 'due_on' => '2026-09-10', 'amount_cents' => 50000, 'days_late' => 60, 'correction_cents' => 220, 'fine_cents' => 1004, 'interest_cents' => 1121, 'charges_cents' => 2345, 'total_cents' => 52345, 'missing_index' => ['2026-10']]],
    'upcoming' => [['entry_id' => 2, 'description' => 'Taxa nov', 'competence' => '2026-11', 'due_on' => '2026-11-20', 'amount_cents' => 50000]], 'overdue_cents' => 50000, 'charges_cents' => 2345, 'upcoming_cents' => 50000, 'missing_index' => ['2026-10']];
$html = $agreementPage('AP-123', 'pages/documents/unit-document', $docData('debt_certificate', ['unitDocument' => $docRow(['content' => $docContent])]), 'doc');
$assert(str_contains($html, 'Certidão de Débitos nº 31') && str_contains($html, 'Taxa &lt;out&gt;') && str_contains($html, 'R$ 523,45') && str_contains($html, 'R$ 10,04') && str_contains($html, 'Sem IPCA lançado de 10/2026')
    && str_contains($html, 'Contas a vencer em aberto') && str_contains($html, 'data-erp-print') && str_contains($html, 'erp-unit-doc__code">ABCD-EFGH-JKMN-PQRS') && str_contains($html, 'Cancelar documento') && !str_contains($html, 'data-unit-document-form'), 'AP-123: certidão com débitos, encargos, código e imprimir');
// ADR 0160: o link usa só CONF_URL_SSL. Aqui ela não é URL (true), então o rodapé mostra o código sem link,
// e nunca um link montado com o Host da requisição ($smokeHost).
$assert(str_contains($html, 'erp-unit-doc__code">ABCD-EFGH-JKMN-PQRS') && !str_contains($html, $smokeHost . '/verificar') && !str_contains($html, '/verificar?'),
    'AP-123: rodapé com o código; link só com a URL configurada, nunca com o Host (ADR 0099 e 0160)');
$assert(str_contains($html, 'data-unit-document-signatures') && str_contains($html, 'Síndico(a)') && str_contains($html, 'Responsável pela emissão'), 'AP-123: linhas de assinatura do síndico e da administradora (ADR 0115)');
$html = $agreementPage('AP-123', 'pages/documents/unit-document', $docData('debt_certificate', ['unitDocument' => $docRow(['result' => 'clear', 'total_cents' => 0, 'status' => 'cancelled', 'cancelled_at' => '2026-11-09 11:00:00', 'cancel_reason' => 'Data <errada>', 'content' => ['overdue' => [], 'upcoming' => []]])]));
$assert(str_contains($html, 'Nada consta.') && str_contains($html, 'erp-unit-doc is-cancelled') && str_contains($html, 'Data &lt;errada&gt;') && !str_contains($html, 'data-unit-document-cancel="'), 'AP-123: nada consta cancelado, sem cancelar de novo');
$html = $agreementPage('AP-125', 'pages/documents/unit-document', $docData('quittance', ['unitDocument' => $docRow(['kind' => 'quittance', 'kind_label' => 'Comprovante de Quitação', 'reference' => '2026-10', 'result' => 'clear', 'total_cents' => 600000, 'content' => ['until_competence' => '2026-10', 'paid_entries' => 12, 'paid_cents' => 600000]])]), 'doc');
$assert(str_contains($html, 'competência até <strong>10/2026</strong>') && str_contains($html, '12 conta(s), no total de R$ 6.000,00'), 'AP-125: quitação até a competência');
$html = $agreementPage('AP-130', 'pages/documents/unit-document', $docData('consent_letter', ['unitDocument' => $docRow(['kind' => 'consent_letter', 'kind_label' => 'Carta de Anuência', 'reference' => 'venda <da unidade>', 'result' => 'clear', 'total_cents' => 0, 'content' => ['overdue' => [], 'upcoming' => $docContent['upcoming']]])]), 'doc');
$assert(str_contains($html, 'para fins de <strong>venda &lt;da unidade&gt;</strong>') && !str_contains($html, 'Contas a vencer em aberto'), 'AP-130: carta com a finalidade escapada, sem contas a vencer');
$html = $agreementPage('AP-124', 'pages/documents/unit-document', $docData('delinquency_declaration', ['documentError' => 'Documento não encontrado no seu escopo.']));
$assert(str_contains($html, 'Documento não encontrado no seu escopo.') && str_contains($html, 'data-unit-document-form'), 'AP-124: documento fora do escopo avisa e mantém a lista');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-130', ['unitDocuments' => null]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-124', ['unitDocuments' => ['data' => []]]) === 'pages/documents/unit-document', 'documentos: sem permissão segue a demonstração');

// Recibo (AP-040, ADR 0057 e 0073): mesma tela dos documentos, com as contas pagas para marcar.
$receiptPaid = [['id' => 71, 'description' => 'Taxa <out>', 'competence' => '2026-10', 'due_on' => '2026-10-10', 'paid_on' => '2026-10-09', 'amount_cents' => 50000],
    ['id' => 72, 'description' => 'Taxa nov', 'competence' => '2026-11', 'due_on' => '2026-11-10', 'paid_on' => '2026-11-08', 'amount_cents' => 52000]];
$html = $agreementPage('AP-040', 'pages/documents/unit-document', $docData('receipt', ['receiptUnit' => 9, 'receiptEntries' => $receiptPaid, 'unitDocuments' => ['data' => [$docRow(['kind' => 'receipt', 'kind_label' => 'Recibo', 'result' => 'clear', 'total_cents' => 102000])], 'total' => 1, 'page' => 1, 'pages' => 1]]), 'issue');
$common($html, 'AP-040', 'Recibos');
$assert(str_contains($html, 'data-kind="receipt"') && str_contains($html, 'data-receipt-form') && substr_count($html, 'name="entry_ids"') === 2 && str_contains($html, 'Taxa &lt;out&gt;') && str_contains($html, 'data-cents="52000"')
    && str_contains($html, '<input type="hidden" name="unit_id" value="9">') && str_contains($html, 'name="unidade" data-erp-autosubmit') && str_contains($html, '>Pago</span>') && !str_contains($html, 'name="until_competence"'), 'AP-040: unidade, contas pagas para marcar e lista com Pago');
$html = $agreementPage('AP-040', 'pages/documents/unit-document', $docData('receipt', ['receiptUnit' => null, 'receiptEntries' => []]));
$assert(!str_contains($html, 'data-receipt-form') && str_contains($html, 'Escolha a unidade para ver as contas pagas'), 'AP-040: sem unidade escolhida, só o seletor');
$html = $agreementPage('AP-040', 'pages/documents/unit-document', $docData('receipt', ['receiptUnit' => 9, 'receiptEntries' => []]));
$assert(str_contains($html, 'Nenhuma conta paga sem recibo') && str_contains($html, 'Apto &lt;101&gt;'), 'AP-040: unidade sem conta paga livre');
$html = $agreementPage('AP-040', 'pages/documents/unit-document', $docData('receipt', ['unitDocument' => $docRow(['kind' => 'receipt', 'kind_label' => 'Recibo', 'result' => 'clear', 'total_cents' => 102000,
    'content' => ['entries' => [['entry_id' => 71, 'description' => 'Taxa <out>', 'competence' => '2026-10', 'due_on' => '2026-10-10', 'paid_on' => '2026-10-09', 'amount_cents' => 50000, 'bank_account' => 'Conta <Itaú>'],
        ['entry_id' => 72, 'description' => 'Taxa nov', 'competence' => '2026-11', 'due_on' => '2026-11-10', 'paid_on' => '2026-11-08', 'amount_cents' => 52000, 'bank_account' => null]], 'paid_cents' => 102000]])]), 'doc');
$assert(str_contains($html, 'Recibo nº 31') && str_contains($html, 'a quantia de <strong>R$ 1.020,00</strong>') && str_contains($html, 'às 2 contas abaixo') && str_contains($html, 'Conta &lt;Itaú&gt;') && str_contains($html, 'ABCD-EFGH-JKMN-PQRS'), 'AP-040: recibo com as contas e o código');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-040', ['unitDocuments' => ['data' => []]]) === 'pages/documents/unit-document' && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-040', ['unitDocuments' => null]) === null, 'AP-040: sem permissão segue a demonstração');

// Lançamentos avulsos (AP-026 a AP-029, ADR 0057 e 0073).
$Shortcut = Source\Controllers\Erp\Connect\EntryShortcutScreens::class;
$receivable = static fn (array $over = []): array => $over + ['id' => 81, 'condominium_id' => 1, 'description' => 'Taxa <nov>', 'competence' => '2026-11', 'due_on' => '2026-11-10', 'amount_cents' => 50000, 'unit_id' => 9, 'document_number' => 'FAT-3', 'status' => 'active'];
$shortcutBase = ['shortcutReady' => true, 'shortcutError' => null, 'shortcutCanManage' => true, 'shortcutContext' => 1];
$html = $agreementPage('AP-026', 'pages/finance/discount', $shortcutBase + ['discountQuery' => '', 'discountUnits' => [9 => 'Apto <101>'],
    'discountEntries' => ['data' => [$receivable(), $receivable(['id' => 82, 'unit_id' => null, 'due_on' => '2020-01-10', 'document_number' => null])], 'total' => 2, 'page' => 1, 'pages' => 1]], 'list');
$common($html, 'AP-026', 'Lançar Desconto');
$assert(str_contains($html, 'data-erp-shortcuts data-kind="discount"') && substr_count($html, 'data-discount-open="') === 2 && str_contains($html, 'data-amount="50000"') && str_contains($html, 'Taxa &lt;nov&gt; · Apto &lt;101&gt;')
    && str_contains($html, '/api/v1/entries/__ID__/discount') && str_contains($html, '>Vencida</span>') && str_contains($html, 'data-form-error') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'AP-026: contas em aberto com Desconto e diálogo');
$html = $agreementPage('AP-026', 'pages/finance/discount', ['shortcutCanManage' => false, 'discountQuery' => 'x<y', 'discountUnits' => [], 'discountEntries' => ['data' => [], 'total' => 0, 'page' => 1, 'pages' => 1]] + $shortcutBase);
$assert(!str_contains($html, 'discount-dialog') && str_contains($html, 'Nenhuma conta encontrada') && str_contains($html, 'value="x&lt;y"'), 'AP-026: sem manage e busca vazia');

$chargeRow = static fn (array $over = []): array => $over + ['id' => 5, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'kind' => 'extra', 'kind_label' => 'Cobrança extra', 'description' => 'Fundo <obras>',
    'chart_account_id' => 3, 'method' => 'fraction', 'total_cents' => 300000, 'units_count' => 3, 'competence' => '2026-11', 'due_on' => '2026-11-30', 'related_entry_id' => null, 'reason' => null,
    'status' => 'active', 'created_at' => '2026-11-09 10:00:00', 'cancelled_at' => null, 'cancel_reason' => null];
$chargeData = static fn (string $kind, array $over = []): array => $over + $shortcutBase + ['chargeKind' => $kind, 'chargeStatus' => null, 'chargeDetail' => null,
    'charges' => ['data' => [$chargeRow(['kind' => $kind]), $chargeRow(['id' => 6, 'kind' => $kind, 'status' => 'cancelled', 'cancel_reason' => 'Duplicada'])], 'total' => 2],
    'chargeUnits' => [['id' => 9, 'condominium_id' => 1, 'label' => 'Apto <101>', 'condominium_name' => 'Residencial Aurora'], ['id' => 10, 'condominium_id' => 1, 'label' => 'Apto 102', 'condominium_name' => 'Residencial Aurora']],
    'chargeAccounts' => [['id' => 3, 'condominium_id' => null, 'label' => '3.1.2 — Receitas <extras>']]];
$html = $agreementPage('AP-028', 'pages/finance/extra-charges', $chargeData('extra'), 'list');
$common($html, 'AP-028', 'Cobranças Extras');
$assert(str_contains($html, 'data-charge-form') && str_contains($html, 'data-charge-kind="extra"') && str_contains($html, 'data-condominium="1"') && substr_count($html, 'name="unit_ids"') === 2 && str_contains($html, 'data-charge-method')
    && str_contains($html, 'Receitas &lt;extras&gt;') && str_contains($html, 'Fundo &lt;obras&gt;') && substr_count($html, 'data-cancel-open') === 1 && str_contains($html, '/api/v1/extra-charges/5/cancel') && str_contains($html, 'id="shortcut-cancel"')
    && str_contains($html, '?cobranca=5') && !str_contains($html, 'name="reason" minlength="5" maxlength="255" required placeholder="Ex.: art.'), 'AP-028: lançar para várias unidades, lista e cancelar só a ativa');
$html = $agreementPage('AP-027', 'pages/finance/extra-charges', $chargeData('fine', ['charges' => ['data' => [$chargeRow(['kind' => 'fine', 'kind_label' => 'Multa avulsa', 'method' => 'fixed', 'reason' => 'Barulho <22h>', 'units_count' => 1])], 'total' => 1]]), 'list');
$common($html, 'AP-027', 'Lançar Multa');
$assert(str_contains($html, 'data-charge-kind="fine"') && str_contains($html, 'name="unit_id" required') && !str_contains($html, 'name="unit_ids"') && !str_contains($html, 'data-charge-method') && str_contains($html, 'id="charge-reason"') && str_contains($html, 'Barulho &lt;22h&gt;'), 'AP-027: multa de uma unidade com motivo');
$html = $agreementPage('AP-027', 'pages/finance/extra-charges', $chargeData('fine', ['shortcutContext' => null, 'chargeUnits' => [], 'chargeAccounts' => []]));
$assert(!str_contains($html, 'data-charge-form') && str_contains($html, 'Escolha um condomínio na barra superior para lançar'), 'AP-027: em todos os condomínios pede a escolha');
$html = $agreementPage('AP-028', 'pages/finance/extra-charges', $chargeData('extra', ['chargeAccounts' => []]));
$assert(!str_contains($html, 'data-charge-form') && str_contains($html, 'Falta uma conta de receita'), 'AP-028: sem conta de receita não lança');
$html = $agreementPage('AP-028', 'pages/finance/extra-charges', $chargeData('extra', ['chargeDetail' => $chargeRow() + ['entries' => [
    ['entry_id' => 91, 'unit_id' => 9, 'unit_label' => 'Apto <101>', 'amount_cents' => 100000, 'status' => 'active', 'paid_on' => '2026-11-20'],
    ['entry_id' => 92, 'unit_id' => 10, 'unit_label' => 'Apto 102', 'amount_cents' => 200000, 'status' => 'active', 'paid_on' => null]]]]), 'detail');
$assert(str_contains($html, 'Cobrança extra nº 5') && str_contains($html, '>Paga</span>') && str_contains($html, '>Em aberto</span>') && str_contains($html, '/financeiro/lancamentos/91') && str_contains($html, 'Total rateado pela fração ideal') && !str_contains($html, 'data-charge-form'), 'AP-028: detalhe com as contas por unidade');
$html = $agreementPage('AP-028', 'pages/finance/extra-charges', $chargeData('extra', ['shortcutCanManage' => false, 'charges' => ['data' => [], 'total' => 0], 'chargeStatus' => 'cancelled']));
$assert(!str_contains($html, 'data-charge-form') && !str_contains($html, 'shortcut-cancel') && str_contains($html, 'Nenhuma cobrança extra nesta situação') && str_contains($html, 'aria-current="page">Canceladas'), 'AP-028: sem manage, filtro vazio');

$transferRow = static fn (array $over = []): array => $over + ['id' => 4, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'from_bank_account_id' => 1, 'from_name' => 'Corrente <Itaú>', 'to_bank_account_id' => 2, 'to_name' => 'Poupança',
    'amount_cents' => 1000000, 'transferred_on' => '2026-11-05', 'description' => 'Aplicação <reserva>', 'status' => 'active', 'created_at' => '2026-11-05 10:00:00', 'cancelled_at' => null, 'cancel_reason' => null];
$transferData = static fn (array $over = []): array => $over + $shortcutBase + ['transferStatus' => null, 'transfers' => ['data' => [$transferRow(), $transferRow(['id' => 3, 'status' => 'cancelled', 'cancel_reason' => 'Valor <errado>'])], 'total' => 2],
    'transferAccounts' => [['id' => 1, 'condominium_id' => 1, 'label' => 'Corrente <Itaú>'], ['id' => 2, 'condominium_id' => 1, 'label' => 'Poupança']]];
$html = $agreementPage('AP-029', 'pages/finance/bank-transfers', $transferData(), 'list');
$common($html, 'AP-029', 'Outros Lançamentos');
$assert(str_contains($html, 'data-transfer-form') && str_contains($html, 'name="from_bank_account_id"') && str_contains($html, 'Corrente &lt;Itaú&gt;') && str_contains($html, 'R$ 10.000,00') && substr_count($html, 'data-cancel-open') === 1
    && str_contains($html, '/api/v1/bank-transfers/4/cancel') && str_contains($html, 'Cancelada: Valor &lt;errado&gt;') && str_contains($html, 'max="' . date('Y-m-d') . '"'), 'AP-029: registrar, lista e cancelar só a ativa');
$html = $agreementPage('AP-029', 'pages/finance/bank-transfers', $transferData(['transferAccounts' => [['id' => 1, 'condominium_id' => 1, 'label' => 'Corrente']]]));
$assert(!str_contains($html, 'data-transfer-form') && str_contains($html, 'duas contas bancárias ativas'), 'AP-029: com uma conta só não registra');
$assert($Shortcut::forContext([['id' => 1, 'condominium_id' => null, 'label' => 'a'], ['id' => 2, 'condominium_id' => 1, 'label' => 'b'], ['id' => 3, 'condominium_id' => 2, 'label' => 'c']], 1) === [['id' => 1, 'condominium_id' => null, 'label' => 'a'], ['id' => 2, 'condominium_id' => 1, 'label' => 'b']]
    && $Shortcut::forContext([['id' => 1, 'condominium_id' => null, 'label' => 'a']], null) === [], 'atalhos: opções do padrão e do condomínio em contexto');
// Gráficos (AP-102 a AP-109; ADR 0083 e 0078).
$CH = Source\Controllers\Erp\Connect\ChartScreens::class;
$chData = static function (string $id, ?array $chart, array $over = []) use ($CH): array {
    $kind = $CH::PAGES[$id];
    $filters = $over['chartFilters'] ?? ['de' => '2026-09', 'ate' => '2026-11', 'natureza' => 'despesa', 'unidade' => $kind === 'consumption-unit' ? 5 : null];
    return $over + ['chartReady' => true, 'chartKind' => $kind, 'chartFilters' => $filters, 'chartError' => null, 'chartNeedsUnit' => false,
        'chartUnits' => [['id' => 5, 'condominium_id' => 1, 'label' => 'Apto <101>'], ['id' => 6, 'condominium_id' => 1, 'label' => 'Apto 102']],
        'chartPanels' => $chart === null ? [] : $CH::panels($chart, $kind), 'chartTable' => $chart === null ? null : $CH::table($chart)];
};
$chMonths = ['2026-09', '2026-10', '2026-11'];
$chCharts = [
    'AP-102' => ['labels' => $chMonths, 'unit' => 'cents', 'series' => [['key' => 'receita', 'label' => 'Receitas', 'data' => [500000, 520000, 510000]], ['key' => 'despesa', 'label' => 'Despesas', 'data' => [450000, 600000, 480000]],
        ['key' => 'result', 'label' => 'Resultado', 'data' => [50000, -80000, 30000]], ['key' => 'overdue', 'label' => 'Vencido em aberto', 'data' => [20000, 15000, 0]]]],
    'AP-103' => ['labels' => $chMonths, 'unit' => 'cents', 'nature' => 'despesa', 'series' => [['key' => '2.1', 'label' => '2.1 <Pessoal>', 'data' => [300000, 300000, 310000]],
        ['key' => '2.2', 'label' => '2.2 Manutenção', 'data' => [80000, 150000, 90000]], ['key' => 'others', 'label' => 'Outras', 'data' => [20000, 10000, 5000]]]],
    'AP-104' => ['labels' => $chMonths, 'unit' => 'consumption', 'unit_id' => 5, 'series' => [['key' => 'agua', 'label' => 'Água', 'data' => [12.5, 11.25, 13.0]], ['key' => 'gas', 'label' => 'Gás', 'data' => [2.0, 2.5, 1.75]]]],
    'AP-105' => ['labels' => $chMonths, 'unit' => 'consumption', 'unit_id' => null, 'series' => [['key' => 'agua', 'label' => 'Água', 'data' => [412.5, 398.0, 420.125]]]],
    'AP-106' => ['labels' => $chMonths, 'unit' => 'cents', 'series' => [['key' => 'received', 'label' => 'Recebido', 'data' => [480000, 500000, 470000]], ['key' => 'paid', 'label' => 'Pago', 'data' => [450000, 610000, 400000]],
        ['key' => 'net', 'label' => 'Saldo do mês', 'data' => [30000, -110000, 70000]]]],
    'AP-107' => ['labels' => $chMonths, 'unit' => 'cents', 'series' => [['key' => 'billed', 'label' => 'Cobrado das unidades', 'data' => [400000, 400000, 0]], ['key' => 'overdue', 'label' => 'Vencido em aberto hoje', 'data' => [40000, 20000, 0]],
        ['key' => 'rate', 'label' => 'Inadimplência (%)', 'unit' => 'percent', 'data' => [10.0, 5.0, null]]]],
    'AP-108' => ['labels' => $chMonths, 'unit' => 'cents', 'series' => [['key' => 'b1', 'label' => 'Corrente <Itaú>', 'data' => [30000, -20000, 10000]], ['key' => 'b2', 'label' => 'Poupança', 'data' => [5000, 5000, 5000]]]],
    'AP-109' => ['labels' => $chMonths, 'unit' => 'count', 'series' => [['key' => 'a1', 'label' => 'Salão de festas', 'data' => [4, 6, 3]], ['key' => 'a2', 'label' => 'Churrasqueira', 'data' => [2, 3, 5]]]],
];
foreach ($chCharts as $chPage => $chChart) {
    $html = $agreementPage($chPage, 'pages/reports/chart', $chData($chPage, $chChart), 'real');
    $common($html, $chPage, $catalog['pages'][$chPage]['name']);
    $assert(str_contains($html, 'class="erp-vchart"') && str_contains($html, 'Tabela do gráfico') && str_contains($html, 'name="de" value="2026-09"') && str_contains($html, 'name="ate" value="2026-11"')
        && str_contains($html, 'set/26') && !str_contains($html, 'Junho a novembro de 2026'), "{$chPage}: gráfico com tabela e período");
}
$html = $agreementPage('AP-102', 'pages/reports/chart', $chData('AP-102', $chCharts['AP-102']));
$assert(str_contains($html, 'erp-vchart__zero') && str_contains($html, 'is-negative') && str_contains($html, '−R$ 800,00') && str_contains($html, 'R$ 15.300,00') && substr_count($html, 'erp-vchart-card') === 1, 'AP-102: um painel, linha do zero e resultado negativo');
$html = $agreementPage('AP-103', 'pages/reports/chart', $chData('AP-103', $chCharts['AP-103']));
$assert(str_contains($html, 'name="natureza"') && str_contains($html, '2.1 &lt;Pessoal&gt;') && str_contains($html, 'erp-swatch--other') && str_contains($html, 'is-stacked'), 'AP-103: contas empilhadas, "Outras" neutra');
$html = $agreementPage('AP-104', 'pages/reports/chart', $chData('AP-104', $chCharts['AP-104']));
$assert(str_contains($html, 'name="unidade"') && str_contains($html, '<option value="5" selected>Apto &lt;101&gt;</option>') && substr_count($html, 'erp-vchart-card') === 2 && str_contains($html, '12,500'), 'AP-104: um painel por tipo de consumo');
$html = $agreementPage('AP-104', 'pages/reports/chart', $chData('AP-104', null, ['chartNeedsUnit' => true, 'chartFilters' => ['de' => '2026-09', 'ate' => '2026-11', 'natureza' => 'despesa', 'unidade' => null]]));
$assert(str_contains($html, 'Escolha a unidade') && !str_contains($html, 'class="erp-vchart"'), 'AP-104: pede a unidade antes do gráfico');
$html = $agreementPage('AP-107', 'pages/reports/chart', $chData('AP-107', $chCharts['AP-107']));
$assert(substr_count($html, 'erp-vchart-card') === 2 && str_contains($html, 'Inadimplência (%)') && str_contains($html, '10,00%') && str_contains($html, '<span class="erp-muted">—</span>'), 'AP-107: taxa em painel próprio, sem total');
$html = $agreementPage('AP-109', 'pages/reports/chart', $chData('AP-109', ['labels' => $chMonths, 'unit' => 'count', 'series' => []]));
$assert(str_contains($html, 'Sem dados no período') && !str_contains($html, 'class="erp-vchart"'), 'AP-109: vazio');
$html = $agreementPage('AP-106', 'pages/reports/chart', $chData('AP-106', null, ['chartError' => 'No máximo 24 competências.']));
$assert(str_contains($html, 'No máximo 24 competências.') && !str_contains($html, 'Tabela do gráfico'), 'AP-106: erro de período');
foreach (array_keys($CH::PAGES) as $chPage) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($chPage, ['chartReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($chPage, ['chartReady' => true]) === 'pages/reports/chart', "{$chPage}: tela própria só com dados");
}

// Relatórios de cobrança (AP-047, AP-048, AP-057 a AP-061, AP-071 e AP-072; ADR 0081 e 0077).
$CR = Source\Controllers\Erp\Connect\CollectionReportScreens::class;
$crData = static function (string $id, array $rows, array $over = []) use ($CR): array {
    [$kind, $variant] = $CR::PAGES[$id];
    $variant = $over['cReportVariant'] ?? $variant;
    $filters = ['de' => '2026-01-01', 'ate' => '2026-11-09', 'referencia' => '2026-11-09', 'situacao' => null, 'agrupar' => $id === 'AP-072' ? $variant : null];
    return $over + ['cReportReady' => true, 'cReportKind' => $kind, 'cReportVariant' => $variant, 'cReportFilters' => $filters, 'cReportError' => null, 'cReport' => [],
        'cReportColumns' => $CR::columns($kind, $variant), 'cReportRows' => $rows, 'cReportCsv' => $CR::csvUrl($kind, $variant, $filters, 1)];
};
$agr = static fn (array $over = []): array => $over + ['id' => 5, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto <101>', 'status' => 'active',
    'created_on' => '2026-10-01', 'installments' => 6, 'paid_installments' => 2, 'original_cents' => 180000, 'fees_cents' => 20000, 'discount_cents' => 5000, 'total_cents' => 195000,
    'paid_cents' => 65000, 'open_cents' => 130000, 'overdue_installments' => 1, 'overdue_cents' => 32500, 'next_due_on' => '2026-11-10'];
$html = $agreementPage('AP-047', 'pages/reports/collection-report', $crData('AP-047', [$agr(), $agr(['id' => 6, 'status' => 'cancelled', 'total_cents' => 100000])]), 'list');
$common($html, 'AP-047', 'Relatório de acordos');
$assert(str_contains($html, 'Apto &lt;101&gt;') && str_contains($html, '>Ativo</span>') && str_contains($html, '>Cancelado</span>') && str_contains($html, '2 de 6') && str_contains($html, '<tfoot>')
    && str_contains($html, 'Total (sem cancelados)') && str_contains($html, 'R$ 1.950,00</td>') && str_contains($html, '/api/v1/reports/agreements?format=csv&amp;condominium_id=1&amp;from=2026-01-01&amp;to=2026-11-09')
    && str_contains($html, 'name="situacao"') && str_contains($html, 'name="de" value="2026-01-01"'), 'AP-047: acordos com situação, rodapé sem cancelados e CSV');
$html = $agreementPage('AP-048', 'pages/reports/collection-report', $crData('AP-048', [['condominium_id' => 1, 'condominium_name' => 'Residencial <Aurora>', 'agreements' => 3, 'cancelled' => 1,
    'original_cents' => 500000, 'fees_cents' => 40000, 'discount_cents' => 10000, 'net_fees_cents' => 30000, 'total_cents' => 530000]]));
$assert(str_contains($html, 'Residencial &lt;Aurora&gt;') && str_contains($html, 'Encargos líquidos') && str_contains($html, 'R$ 300,00') && !str_contains($html, 'name="situacao"'), 'AP-048: taxas por condomínio');
$unitRow = ['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto 101', 'entries' => 2, 'amount_cents' => 185000, 'charges_cents' => 4200,
    'total_cents' => 189200, 'oldest_due_on' => '2026-07-10', 'max_days_late' => 122];
foreach (['AP-057' => [['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'units' => 2, 'entries' => 4, 'amount_cents' => 370000, 'charges_cents' => 8400, 'total_cents' => 378400, 'max_days_late' => 122]],
    'AP-058' => [$unitRow], 'AP-059' => [$unitRow + ['entry_id' => 70, 'description' => 'Taxa <julho>', 'competence' => '2026-07', 'due_on' => '2026-07-10', 'days_late' => 122, 'bucket' => '90+']],
    'AP-060' => [['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'account' => '1.1 Taxa condominial', 'units' => 2, 'entries' => 4, 'amount_cents' => 370000, 'charges_cents' => 8400, 'total_cents' => 378400]],
    'AP-061' => [$unitRow + ['responsible' => ['name' => 'Ana <Souza>', 'document' => '*********01', 'email' => 'ana@x.com', 'phone' => '11 9999', 'relationship' => 'owner']], $unitRow + ['unit_label' => 'Apto 102', 'responsible' => null]]] as $crPage => $crRows) {
    $html = $agreementPage($crPage, 'pages/reports/collection-report', $crData($crPage, $crRows), 'list');
    $common($html, $crPage, $catalog['pages'][$crPage]['name']);
    $assert(str_contains($html, 'name="referencia" value="2026-11-09"') && !str_contains($html, 'name="de"') && str_contains($html, 'view=' . $CR::PAGES[$crPage][1]) && str_contains($html, '<tfoot>')
        && str_contains($html, '122 dia(s)') === ($crPage !== 'AP-060'), "{$crPage}: inadimplentes na data de referência");
}
$assert(str_contains($html, 'Ana &lt;Souza&gt;') && str_contains($html, '*********01') && str_contains($html, 'o documento aparece mascarado') && str_contains($html, '<span class="erp-muted">—</span>'), 'AP-061: responsável mascarado e aviso de dados pessoais');
$html = $agreementPage('AP-071', 'pages/reports/collection-report', $crData('AP-071', [['entry_id' => 9, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_label' => 'Apto 101', 'description' => '=CMD()',
    'competence' => '2026-10', 'due_on' => '2026-10-10', 'paid_on' => '2026-10-15', 'amount_cents' => 92500, 'days_late' => 5, 'bank_account_id' => 3, 'bank_account' => 'Corrente', 'chart_account' => '1.1 Taxa']]), 'list');
$common($html, 'AP-071', 'Recebimentos');
$assert(str_contains($html, '15/10/2026') && str_contains($html, '5 dia(s)') && str_contains($html, 'group=entry') && !str_contains($html, 'name="agrupar"'), 'AP-071: recebimentos em linhas');
$html = $agreementPage('AP-072', 'pages/reports/collection-report', $crData('AP-072', [['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'account' => 'Corrente <Itaú>', 'entries' => 3, 'amount_cents' => 277500, 'late' => 1]],
    ['cReportVariant' => 'chart_account']), 'list');
$assert(str_contains($html, 'name="agrupar"') && str_contains($html, '<option value="chart_account" selected>') && str_contains($html, 'Corrente &lt;Itaú&gt;') && str_contains($html, 'group=chart_account'), 'AP-072: agrupado pela conta escolhida');
$html = $agreementPage('AP-071', 'pages/reports/collection-report', $crData('AP-071', [], ['cReportError' => 'O período passa de 366 dias.']));
$assert(str_contains($html, 'O período passa de 366 dias.') && !str_contains($html, 'Exportar CSV'), 'AP-071: erro de período sem exportar');
$html = $agreementPage('AP-071', 'pages/reports/collection-report', $crData('AP-071', []));
$assert(str_contains($html, 'Nada neste filtro') && !str_contains($html, 'Exportar CSV'), 'AP-071: vazio sem exportar');
$assert($CR::date('2026-02-30') === null && $CR::date('2026-02-28') === '2026-02-28' && $CR::permissions('AP-061') === ['erp.agreements.view', 'erp.residents.view'] && $CR::permissions('AP-072') === ['erp.entries.view'], 'relatórios de cobrança: regras puras');
foreach (array_keys($CR::PAGES) as $crPage) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($crPage, ['cReportReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($crPage, ['cReportReady' => true]) === 'pages/reports/collection-report', "{$crPage}: tela própria só com dados");
}

// Cobrança e multas (AP-116, AP-117 e AP-039; ADR 0080 e 0076).
$Coll = Source\Controllers\Erp\Connect\CollectionScreens::class;
$collBase = ['collReady' => true, 'collContext' => 1, 'collError' => null, 'collCanManage' => true];
$fine = static fn (array $over = []): array => $over + ['id' => 12, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto <101>', 'description' => 'Multa <barulho>',
    'reason' => 'Art. 12 <regulamento>', 'entry_id' => 90, 'amount_cents' => 25000, 'due_on' => '2026-10-10', 'paid_on' => null, 'state' => 'overdue', 'occurrence_id' => 44, 'reservation_id' => null];
$html = $agreementPage('AP-116', 'pages/collections/pending-fines', $collBase + ['finesPaid' => false, 'fines' => ['data' => [$fine(), $fine(['id' => 13, 'state' => 'open', 'occurrence_id' => null, 'reservation_id' => 8, 'due_on' => '2026-12-10']),
    $fine(['id' => 14, 'state' => 'open', 'occurrence_id' => null])], 'totals' => ['fines' => 3, 'open_cents' => 75000, 'overdue' => 1]]], 'list');
$common($html, 'AP-116', 'Multas Pendentes');
$assert(str_contains($html, 'Multa &lt;barulho&gt;') && str_contains($html, 'Art. 12 &lt;regulamento&gt;') && str_contains($html, '/operacional/ocorrencias/44') && str_contains($html, 'Reserva nº 8') && str_contains($html, 'Não registrada')
    && str_contains($html, '>Vencida</span>') && str_contains($html, 'em aberto R$ 750,00') && str_contains($html, '/financeiro/lancar-multa-avulsa?cobranca=12') && str_contains($html, '?pagas=1'), 'AP-116: multas com origem, situação e totais');
$html = $agreementPage('AP-116', 'pages/collections/pending-fines', $collBase + ['finesPaid' => true, 'fines' => ['data' => [], 'totals' => ['fines' => 0, 'open_cents' => 0, 'overdue' => 0]]]);
$assert(str_contains($html, 'Nenhuma multa pendente') && str_contains($html, '?pagas=1" aria-current="page"'), 'AP-116: vazio com o filtro das pagas');

$deskRow = static fn (array $over = []): array => $over + ['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto <101>', 'entries' => 2, 'amount_cents' => 185000, 'total_cents' => 190000,
    'max_days_late' => 122, 'last_contact' => ['id' => 3, 'at' => '2026-11-01 10:00:00', 'channel' => 'phone', 'outcome' => 'promise', 'promise_on' => '2026-11-05', 'next_action_on' => '2026-11-08'],
    'promise_broken' => true, 'action_due' => true, 'legal_case' => null];
$deskData = $collBase + ['desk' => ['as_of' => '2026-11-09', 'data' => [$deskRow(), $deskRow(['unit_id' => 6, 'unit_label' => 'Apto 102', 'last_contact' => null, 'promise_broken' => false, 'action_due' => false, 'legal_case' => ['id' => 9, 'status' => 'filed']])],
    'totals' => ['units' => 2, 'entries' => 4, 'amount_cents' => 370000, 'promise_broken' => 1, 'action_due' => 1, 'without_contact' => 1]], 'deskUnit' => null, 'deskContacts' => null];
$html = $agreementPage('AP-117', 'pages/collections/desk', $deskData, 'list');
$common($html, 'AP-117', 'Atendimento Cobrança');
$assert(str_contains($html, 'Apto &lt;101&gt;') && str_contains($html, '>Promessa quebrada</span>') && str_contains($html, 'Promessa de pagamento para 05/11/2026') && str_contains($html, '08/11/2026 (vencida)')
    && str_contains($html, 'Caso judicial: Ajuizado') && str_contains($html, '?unidade=5#atender') && str_contains($html, 'R$ 3.700,00') && !str_contains($html, 'data-contact-form'), 'AP-117: fila com promessa quebrada, ação vencida e caso');
$html = $agreementPage('AP-117', 'pages/collections/desk', ['deskUnit' => $deskRow(), 'deskContacts' => [['id' => 3, 'unit_id' => 5, 'channel' => 'phone', 'outcome' => 'promise', 'outcome_label' => 'Promessa de pagamento', 'contact_name' => 'Ana <S>',
    'promise_on' => '2026-11-05', 'promise_cents' => 50000, 'next_action_on' => '2026-11-08', 'note' => "Disse que <paga>\nsexta", 'open_cents' => 185000, 'created_by' => 1, 'created_at' => '2026-11-01 10:00:00']]] + $deskData, 'unit');
$assert(str_contains($html, 'id="atender"') && str_contains($html, 'data-contact-form data-api="https://' . $smokeHost . '/api/v1/collection-contacts" data-unit="5"') && str_contains($html, 'Disse que &lt;paga&gt;<br />') && str_contains($html, 'Ana &lt;S&gt;')
    && str_contains($html, '(R$ 500,00)') && str_contains($html, '?abrir=5#abrir-caso') && str_contains($html, 'aria-current="true"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'AP-117: histórico e registrar contato');
$html = $agreementPage('AP-117', 'pages/collections/desk', ['collCanManage' => false, 'deskUnit' => $deskRow(), 'deskContacts' => []] + $deskData);
$assert(!str_contains($html, 'data-contact-form') && !str_contains($html, '?abrir=') && str_contains($html, 'Nenhum contato registrado'), 'AP-117: sem permissão só consulta');

$caseRow = static fn (array $over = []): array => $over + ['id' => 9, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 6, 'unit_label' => 'Apto 102', 'lawyer_id' => 3, 'lawyer_name' => 'Dra. <Lima>',
    'status' => 'filed', 'status_label' => 'Ajuizado', 'case_number' => '0001234-56.2026.8.26.0100', 'court' => '2ª Vara Cível', 'opened_on' => '2026-10-01', 'claimed_cents' => 185000, 'closed_on' => null, 'close_reason' => null,
    'next_statuses' => ['suspended', 'agreement', 'closed']];
$lateUnit = ['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto <101>', 'amount_cents' => 185000, 'max_days_late' => 122, 'oldest_due_on' => '2026-07-10',
    'entries' => [['id' => 70, 'description' => 'Taxa <julho>', 'competence' => '2026-07', 'due_on' => '2026-07-10', 'amount_cents' => 92500, 'days_late' => 122], ['id' => 71, 'description' => 'Taxa agosto', 'competence' => '2026-08', 'due_on' => '2026-08-10', 'amount_cents' => 92500, 'days_late' => 91]]];
$caseData = $collBase + ['caseStatus' => null, 'cases' => ['data' => [$caseRow(), $caseRow(['id' => 10, 'status' => 'closed', 'lawyer_name' => null, 'case_number' => null, 'court' => null])], 'total' => 2],
    'caseDetail' => null, 'caseOpenUnit' => null, 'caseOpenUnits' => [$lateUnit], 'caseLawyers' => [['id' => 3, 'name' => 'Dra. <Lima>', 'oab' => 'SP 123456']]];
$html = $agreementPage('AP-039', 'pages/collections/legal-cases', $caseData, 'list');
$common($html, 'AP-039', 'Cobrança Judicial');
$assert(str_contains($html, 'Só acompanhamento: nada é enviado') && str_contains($html, 'name="abrir"') && str_contains($html, 'Apto &lt;101&gt; · R$ 1.850,00') && str_contains($html, '>Ajuizado</span>') && str_contains($html, 'Dra. &lt;Lima&gt;')
    && str_contains($html, '?caso=9') && str_contains($html, '?situacao=closed') && !str_contains($html, 'data-case-open-form'), 'AP-039: lista, filtro e escolher a unidade');
$html = $agreementPage('AP-039', 'pages/collections/legal-cases', ['caseOpenUnit' => $lateUnit] + $caseData, 'open');
$assert(str_contains($html, 'data-case-open-form data-api="https://' . $smokeHost . '/api/v1/legal-cases" data-unit="5"') && substr_count($html, 'name="entry_ids"') === 2 && str_contains($html, 'Taxa &lt;julho&gt;')
    && str_contains($html, 'id="abrir-caso"') && str_contains($html, 'Dra. &lt;Lima&gt; · OAB SP 123456') && !str_contains($html, 'name="abrir"'), 'AP-039: abrir caso com as contas da unidade');
$detail = $caseRow() + ['still_open_cents' => 92500, 'entries' => [['entry_id' => 70, 'description' => 'Taxa <julho>', 'due_on' => '2026-07-10', 'claimed_cents' => 92500, 'amount_cents' => 92500, 'paid_on' => '2026-11-02', 'status' => 'active'],
    ['entry_id' => 71, 'description' => 'Taxa agosto', 'due_on' => '2026-08-10', 'claimed_cents' => 92500, 'amount_cents' => 92500, 'paid_on' => null, 'status' => 'active']],
    'events' => [['id' => 1, 'kind' => 'opened', 'from_status' => null, 'to_status' => 'preparing', 'note' => 'Aberto <nota>', 'happened_on' => '2026-10-01', 'created_by' => 1, 'created_at' => '2026-10-01 09:00:00'],
        ['id' => 2, 'kind' => 'status', 'from_status' => 'preparing', 'to_status' => 'filed', 'note' => 'Protocolado', 'happened_on' => '2026-10-15', 'created_by' => 1, 'created_at' => '2026-10-15 09:00:00']]];
$html = $agreementPage('AP-039', 'pages/collections/legal-cases', ['caseDetail' => $detail] + $caseData, 'detail');
$assert(str_contains($html, 'Caso judicial nº 9') && str_contains($html, 'Ainda em aberto') && str_contains($html, 'R$ 925,00') && str_contains($html, 'Paga em 02/11/2026') && str_contains($html, 'Em preparação → Ajuizado')
    && str_contains($html, 'Aberto &lt;nota&gt;') && str_contains($html, '/api/v1/legal-cases/9/events') && str_contains($html, '<option value="closed">Encerrado</option>') && str_contains($html, 'value="3" selected')
    && str_contains($html, 'min="2026-10-01"') && !str_contains($html, 'name="abrir"'), 'AP-039: detalhe com contas, andamento e transições');
$html = $agreementPage('AP-039', 'pages/collections/legal-cases', ['caseDetail' => ['status' => 'closed', 'closed_on' => '2026-11-01', 'close_reason' => 'Acordo <pago>', 'next_statuses' => []] + $detail] + $caseData);
$assert(!str_contains($html, 'data-case-event-form') && str_contains($html, 'Acordo &lt;pago&gt;') && str_contains($html, 'Encerrado em'), 'AP-039: encerrado não recebe andamento');
$html = $agreementPage('AP-039', 'pages/collections/legal-cases', ['collContext' => null, 'caseOpenUnits' => []] + $caseData);
$assert(str_contains($html, 'Escolha um condomínio na barra superior para abrir um caso') && str_contains($html, 'Residencial Aurora'), 'AP-039: todos os condomínios');
$assert($Coll::fineBadge('overdue') === ['Vencida', 'danger'] && $Coll::caseBadge('closed')[0] === 'Encerrado' && $Coll::eventTitle(['kind' => 'closed']) === 'Encerrado', 'cobrança: regras puras');
foreach ($Coll::PAGES as $collPage => $collTemplate) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($collPage, ['collReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($collPage, ['collReady' => true]) === $collTemplate, "{$collPage}: tela própria só com dados");
}

// Comunicação (AP-111, AP-137, AP-138 e AP-139; ADR 0059 e 0075).
$Comm = Source\Controllers\Erp\Connect\CommunicationScreens::class;
$future = date('Y-m-d H:i:s', strtotime('+3 days'));
$annRow = static fn (array $over = []): array => $over + ['id' => 31, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'kind' => 'general', 'subject' => 'Obra na <garagem>', 'audience' => 'all',
    'send_at' => '2026-11-08 10:00:00', 'scheduled' => false, 'units_count' => 12, 'status' => 'active', 'created_at' => '2026-11-08 09:58:00', 'cancelled_at' => null, 'cancel_reason' => null,
    'delivery' => ['pending' => 0, 'processing' => 0, 'sent' => 12, 'failed' => 0, 'cancelled' => 0, 'recipients' => 19]];
$commData = static fn (array $over = []): array => $over + ['commReady' => true, 'commContext' => 1, 'commError' => null, 'commCanManage' => true, 'commKind' => 'general', 'announcementDetail' => null,
    'commUnits' => [['id' => 5, 'condominium_id' => 1, 'label' => 'Apto <101>'], ['id' => 6, 'condominium_id' => 1, 'label' => 'Apto 102']],
    'announcements' => ['data' => [$annRow(), $annRow(['id' => 32, 'subject' => 'Assembleia', 'send_at' => $future, 'scheduled' => true, 'delivery' => ['pending' => 12, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'recipients' => 0]]),
        $annRow(['id' => 33, 'status' => 'cancelled', 'delivery' => ['pending' => 0, 'processing' => 0, 'sent' => 2, 'failed' => 0, 'cancelled' => 10, 'recipients' => 3]])], 'total' => 3]];
$html = $agreementPage('AP-111', 'pages/communication/announcements', $commData(), 'list');
$common($html, 'AP-111', 'Comunicados Gerais');
$assert(str_contains($html, 'data-comm-delivery-notice') && str_contains($html, 'nenhum e-mail sai') && str_contains($html, 'data-delivery-mode="log"'), 'AP-111: modo só registro avisado na tela (teste da main, item 10)');
$assert(str_contains($html, 'data-erp-communication') && str_contains($html, 'data-announce-form data-api="https://' . $smokeHost . '/api/v1/announcements" data-condominium="1"') && str_contains($html, 'maxlength="5000"')
    && str_contains($html, 'Apto &lt;101&gt;') && str_contains($html, 'Todas as unidades ativas (2)') && str_contains($html, 'type="datetime-local"') && str_contains($html, 'Obra na &lt;garagem&gt;')
    && str_contains($html, '>Enviado</span>') && str_contains($html, '>Agendado</span>') && str_contains($html, '>Cancelado</span>') && substr_count($html, 'data-cancel-open') === 1
    && str_contains($html, '/api/v1/announcements/32/cancel') && str_contains($html, 'id="shortcut-cancel"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'AP-111: enviar, lista com situação e cancelar só o que não saiu');
$html = $agreementPage('AP-111', 'pages/communication/announcements', $commData(['commContext' => null, 'commUnits' => []]));
$assert(!str_contains($html, 'data-announce-form') && str_contains($html, 'Escolha um condomínio na barra superior para enviar') && str_contains($html, '<th scope="col">Condomínio</th>'), 'AP-111: em todos os condomínios pede a escolha');
$html = $agreementPage('AP-111', 'pages/communication/announcements', $commData(['commCanManage' => false]));
$assert(!str_contains($html, 'data-announce-form') && !str_contains($html, 'data-cancel-open') && !str_contains($html, 'id="shortcut-cancel"'), 'AP-111: sem permissão de gravar só consulta');
$html = $agreementPage('AP-111', 'pages/communication/announcements', $commData(['announcementDetail' => $annRow(['id' => 32, 'send_at' => $future, 'scheduled' => true, 'body' => "Linha 1\n<script>x</script>",
    'delivery' => ['pending' => 12, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'recipients' => 0]])]), 'detail');
$assert(str_contains($html, 'Comunicado nº 32') && str_contains($html, 'Linha 1<br />') && str_contains($html, '&lt;script&gt;') && !str_contains($html, '<script>x') && str_contains($html, 'Cancelar o que não saiu')
    && str_contains($html, 'Sai em') && !str_contains($html, 'data-announce-form'), 'AP-111: detalhe com texto escapado e cancelar');
$html = $agreementPage('AP-111', 'pages/communication/announcements', $commData(['announcementDetail' => $annRow(['status' => 'cancelled', 'cancelled_at' => '2026-11-08 11:00:00', 'cancel_reason' => 'Data <errada>', 'body' => 'Texto'])]));
$assert(str_contains($html, 'Cancelado em 08/11/2026 11:00') && str_contains($html, 'Data &lt;errada&gt;') && !str_contains($html, 'Cancelar o que não saiu'), 'AP-111: cancelado mostra o motivo');

$late = static fn (array $over = []): array => $over + ['condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 5, 'unit_label' => 'Apto <101>', 'entries' => [['id' => 1], ['id' => 2]],
    'amount_cents' => 185000, 'charges_cents' => 0, 'total_cents' => 185000, 'oldest_due_on' => '2026-07-10', 'max_days_late' => 122];
$collData = $commData(['commKind' => 'collection', 'collectionAsOf' => '2026-11-09', 'collectionUnits' => [$late(), $late(['unit_id' => 6, 'unit_label' => 'Apto 102', 'max_days_late' => 3]), $late(['unit_id' => 7, 'unit_label' => 'Apto 103'])],
    'collectionReminded' => [7 => true], 'collectionBilling' => [5 => 2, 6 => 0, 7 => 1],
    'announcements' => ['data' => [$annRow(['kind' => 'collection', 'subject' => 'Aviso de débitos em aberto', 'audience' => 'units'])], 'total' => 1]]);
$html = $agreementPage('AP-137', 'pages/communication/announcements', $collData, 'collection');
$common($html, 'AP-137', 'Cobrança');
$assert(str_contains($html, 'data-collection-form data-api="https://' . $smokeHost . '/api/v1/announcements/collection" data-condominium="1" data-as-of="2026-11-09"') && str_contains($html, 'R$ 1.850,00')
    && str_contains($html, 'data-days="122"') && str_contains($html, 'Avisada nos últimos 7 dias') && str_contains($html, 'value="7" disabled') && str_contains($html, 'value="5" checked')
    && str_contains($html, 'Ninguém recebe cobrança por e-mail') && str_contains($html, '2 pessoa(s)') && str_contains($html, 'name="min_days_late"') && str_contains($html, '?sem=cobranca')
    && str_contains($html, 'name="referencia" value="2026-11-09"') && !str_contains($html, 'data-announce-form') && str_contains($html, 'Avisos enviados'), 'AP-137: unidades em atraso, avisadas de fora e enviar');
$html = $agreementPage('AP-137', 'pages/communication/announcements', ['collectionReminded' => [5 => true, 6 => true, 7 => true]] + $collData);
$assert(!str_contains($html, 'Enviar aviso de cobrança') && str_contains($html, 'Todas as unidades em atraso já foram avisadas'), 'AP-137: todas avisadas não envia');
$html = $agreementPage('AP-137', 'pages/communication/announcements', ['collectionUnits' => [], 'commError' => 'Data de referência inválida ou futura. Mostrando a de hoje.'] + $collData);
$assert(str_contains($html, 'Nenhuma unidade em atraso') && str_contains($html, 'Data de referência inválida'), 'AP-137: sem atraso e data inválida');

$html = $agreementPage('AP-138', 'pages/communication/announcements', $commData(['commKind' => null, 'announcements' => ['data' => [$annRow(['id' => 32, 'kind' => 'collection', 'subject' => 'Aviso de débitos em aberto', 'send_at' => $future, 'scheduled' => true,
    'delivery' => ['pending' => 4, 'processing' => 0, 'sent' => 0, 'failed' => 0, 'cancelled' => 0, 'recipients' => 0]])], 'total' => 1]]), 'list');
$common($html, 'AP-138', 'Agendada');
$assert(str_contains($html, 'Ainda vão sair') && str_contains($html, 'Aviso de cobrança') && !str_contains($html, 'data-announce-form') && !str_contains($html, 'data-collection-form') && substr_count($html, 'data-cancel-open') === 1, 'AP-138: agendados com cancelar');
$html = $agreementPage('AP-138', 'pages/communication/announcements', $commData(['commKind' => null, 'announcements' => ['data' => [], 'total' => 0]]));
$assert(str_contains($html, 'Nenhum envio agendado'), 'AP-138: vazio');

$person = static fn (array $over = []): array => $over + ['resident_id' => 21, 'name' => 'Ana <Souza>', 'relationship' => 'owner', 'email' => 'a***@e***.com', 'valid_email' => true, 'notices' => true, 'billing' => true];
$addrData = ['commReady' => true, 'commContext' => 1, 'commError' => null, 'commCanManage' => true, 'addressFilter' => null, 'addresses' => ['data' => [
    ['unit_id' => 5, 'unit_label' => 'Apto <101>', 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'people' => [$person(), $person(['resident_id' => 22, 'name' => 'Bruno', 'relationship' => 'tenant', 'notices' => false, 'billing' => false])], 'notices' => 1, 'billing' => 1],
    ['unit_id' => 6, 'unit_label' => 'Apto 102', 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'people' => [$person(['resident_id' => 23, 'name' => 'Carla', 'email' => null, 'valid_email' => false, 'notices' => false, 'billing' => false])], 'notices' => 0, 'billing' => 0],
    ['unit_id' => 7, 'unit_label' => 'Apto 103', 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'people' => [], 'notices' => 0, 'billing' => 0]],
    'totals' => ['units' => 3, 'without_notices' => 2, 'without_billing' => 2]], 'addressRelationships' => $Comm::RELATIONSHIPS];
$addrData['addressUnits'] = $addrData['addresses']['data'];
$html = $agreementPage('AP-139', 'pages/communication/addresses', $addrData, 'list');
$common($html, 'AP-139', 'Endereços');
$assert(str_contains($html, 'Ana &lt;Souza&gt;') && str_contains($html, 'a***@e***.com') && str_contains($html, 'Proprietário') && str_contains($html, 'Inquilino') && str_contains($html, '>Recusou</span>') && str_contains($html, '>Não autorizou</span>')
    && str_contains($html, 'Sem e-mail válido') && str_contains($html, 'Nenhuma pessoa ativa nesta unidade') && str_contains($html, '/cadastros/proprietarios-e-inquilinos/21') && str_contains($html, 'rowspan="2"')
    && str_contains($html, '<th scope="row"') && str_contains($html, '3 unidade(s).'), 'AP-139: pessoas por unidade, e-mail mascarado e o que recebem');
$html = $agreementPage('AP-139', 'pages/communication/addresses', ['addressFilter' => 'comunicado', 'addressUnits' => $Comm::filterAddresses($addrData['addresses']['data'], 'comunicado')] + $addrData);
$assert(str_contains($html, '2 unidade(s) no filtro') && !str_contains($html, 'Ana &lt;Souza&gt;') && str_contains($html, '?sem=comunicado" aria-current="page"'), 'AP-139: filtro sem comunicado');
$assert($Comm::statusOf(['status' => 'active', 'scheduled' => false, 'delivery' => ['failed' => 1]]) === ['Com falha', 'danger'] && $Comm::cancellable(['status' => 'active', 'delivery' => ['pending' => 1]])
    && !$Comm::cancellable(['status' => 'cancelled', 'delivery' => ['pending' => 1]]) && $Comm::referenceDate('2999-01-01') === null, 'comunicação: regras puras');
foreach (array_keys($Comm::PAGES) as $commPage) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($commPage, ['commReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($commPage, ['commReady' => true]) === $Comm::PAGES[$commPage], "{$commPage}: tela própria só com dados");
}

// Importações (AP-140, AP-142 a AP-146 e AP-044; ADR 0058 e 0074).
$Imp = Source\Controllers\Erp\Connect\ImportScreens::class;
$importRow = static fn (array $over = []): array => $over + ['id' => 41, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'kind' => 'entries', 'kind_label' => 'Lançamentos (planilha)', 'page' => 'AP-144',
    'bank_account_id' => null, 'original_name' => 'lancamentos <nov>.xlsx', 'sha256' => str_repeat('a', 64), 'size_bytes' => 2048, 'status' => 'applied', 'rows_count' => 12, 'error_count' => 0,
    'summary' => ['rows' => 12, 'errors' => 0, 'total_cents' => 1250000, 'applied' => ['entries' => 12]], 'created_at' => '2026-11-09 09:00:00', 'applied_at' => '2026-11-09 09:05:00'];
$importData = static fn (string $kind, array $over = []): array => $over + ['importReady' => true, 'importKind' => $kind, 'importContext' => 1, 'importDetail' => null, 'importError' => null,
    'importBankAccounts' => [], 'pendingLines' => null, 'lineDetail' => null, 'canImport' => true, 'canResolveLines' => true,
    'imports' => ['data' => [$importRow(['kind' => $kind]), $importRow(['id' => 42, 'kind' => $kind, 'status' => 'previewed', 'error_count' => 3, 'applied_at' => null])], 'total' => 2]];
$html = $agreementPage('AP-144', 'pages/imports/import', $importData('entries'), 'list');
$common($html, 'AP-144', 'Lançamentos (Planilha)');
$assert(str_contains($html, 'data-erp-imports data-kind="entries"') && str_contains($html, 'data-import-form data-condominium="1"') && str_contains($html, 'accept=".csv,.xlsx"') && str_contains($html, '<code>natureza</code>')
    && str_contains($html, '<code>pago_em</code>') && str_contains($html, 'download="modelo-entries.csv"') && str_contains($html, 'natureza%3Bdescricao%3Bvalor') && str_contains($html, '/api/v1/imports/preview')
    && str_contains($html, 'data-import-preview') && str_contains($html, 'lancamentos &lt;nov&gt;.xlsx') && str_contains($html, '>Prévia com erros</span>') && str_contains($html, '>Aplicada</span>') && str_contains($html, '?importacao=42')
    && !str_contains($html, 'Linhas pendentes') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html), 'AP-144: enviar, modelo, prévia e lista');
// NF-e em XML (AP-141; ADR 0086 e 0079): só quando o tipo existe no serviço (ciclo 35 do Avançar).
if (isset(Source\Services\Erp\Imports\Imports::KINDS['nfe'])) {
    $html = $agreementPage('AP-141', 'pages/imports/import', $importData('nfe', ['importExpenseAccounts' => [['id' => 9, 'condominium_id' => 1, 'label' => '2.2 Manutenção <predial>']]]), 'list');
    $common($html, 'AP-141', 'Importação NF-e (xml)');
    $assert(str_contains($html, 'data-kind="nfe"') && str_contains($html, 'accept=".xml"') && str_contains($html, 'name="chart_account_id" required') && str_contains($html, '2.2 Manutenção &lt;predial&gt;') && str_contains($html, 'name="due_on"') && str_contains($html, 'data-max-bytes="1000000"') && str_contains($html, 'até 1 MB')
        && !str_contains($html, 'Baixar modelo CSV') && !str_contains($html, '5.000 linhas'), 'AP-141: XML com a conta de despesa, sem modelo');
    $html = $agreementPage('AP-141', 'pages/imports/import', $importData('nfe', ['importExpenseAccounts' => []]));
    $assert(!str_contains($html, 'data-import-form') && str_contains($html, 'não tem conta de despesa analítica ativa'), 'AP-141: sem conta de despesa não envia');
}
$html = $agreementPage('AP-143', 'pages/imports/import', $importData('fractions', ['importContext' => null]));
$assert(!str_contains($html, 'data-import-form') && str_contains($html, 'Escolha um condomínio na barra superior para importar'), 'AP-143: em todos os condomínios pede a escolha');
$html = $agreementPage('AP-145', 'pages/imports/import', $importData('unit_entries', ['canImport' => false, 'imports' => ['data' => [], 'total' => 0]]));
$assert(!str_contains($html, 'data-import-form') && str_contains($html, 'Nenhuma importação') && str_contains($html, 'As importações enviadas aparecem aqui.'), 'AP-145: sem permissão de gravar só consulta');
$html = $agreementPage('AP-144', 'pages/imports/import', $importData('entries', ['importDetail' => $importRow(['status' => 'previewed', 'applied_at' => null, 'summary' => ['rows' => 12, 'errors' => 0, 'total_cents' => 1250000]])]), 'detail');
$assert(str_contains($html, 'Importação nº 41') && str_contains($html, 'data-import-apply="41"') && str_contains($html, 'R$ 12.500,00') && str_contains($html, 'envie o mesmo arquivo') && !str_contains($html, 'data-import-form'), 'AP-144: detalhe em prévia com Aplicar');
$html = $agreementPage('AP-144', 'pages/imports/import', $importData('entries', ['importDetail' => $importRow()]));
$assert(!str_contains($html, 'data-import-apply="41"') && str_contains($html, 'Lançamentos: 12'), 'AP-144: aplicada mostra o que gravou, sem aplicar de novo');

$stmtLine = static fn (array $over = []): array => $over + ['id' => 7, 'fitid' => 'F<1>', 'kind' => 'debit', 'posted_on' => '2026-11-03', 'amount_cents' => -45000, 'memo' => 'PAG <BOLETO>', 'status' => 'unmatched', 'entry_id' => null, 'transfer_id' => null, 'note' => null,
    'bank_account_id' => 3, 'bank_account' => 'Corrente <Itaú>'];
$ofxData = $importData('ofx', ['importBankAccounts' => [['id' => 3, 'condominium_id' => 1, 'label' => 'Corrente <Itaú>']],
    'imports' => ['data' => [$importRow(['kind' => 'ofx', 'kind_label' => 'Extrato bancário (OFX)', 'original_name' => 'extrato.ofx'])], 'total' => 1],
    'pendingLines' => ['data' => [$stmtLine(), $stmtLine(['id' => 8, 'amount_cents' => 120000, 'kind' => 'credit', 'memo' => 'PIX RECEBIDO'])], 'total' => 2],
    'lineDetail' => ['line' => ['id' => 7, 'condominium_id' => 1, 'bank_account_id' => 3, 'bank_account' => 'Corrente <Itaú>', 'fitid' => 'F1', 'kind' => 'debit', 'posted_on' => '2026-11-03', 'amount_cents' => -45000, 'memo' => 'PAG <BOLETO>', 'status' => 'unmatched'],
        'candidates' => [['id' => 61, 'description' => 'Elevador <manutenção>', 'paid_on' => '2026-11-02', 'amount_cents' => 45000, 'unit_label' => null, 'document_number' => 'NF 12', 'days' => 1],
            ['id' => 62, 'description' => 'Limpeza', 'paid_on' => '2026-11-03', 'amount_cents' => 45000, 'unit_label' => null, 'document_number' => null, 'days' => 0]]]]);
$html = $agreementPage('AP-140', 'pages/imports/import', $ofxData, 'pending');
$common($html, 'AP-140', 'Extrato Bancário (OFX)');
$assert(str_contains($html, 'name="bank_account_id" required') && str_contains($html, 'accept=".ofx"') && !str_contains($html, 'download="modelo-') && str_contains($html, 'Linhas pendentes') && str_contains($html, 'PAG &lt;BOLETO&gt;')
    && str_contains($html, '− R$ 450,00') && str_contains($html, 'R$ 1.200,00') && str_contains($html, '?linha=8#conciliar') && str_contains($html, 'id="conciliar"') && str_contains($html, '/api/v1/bank-statement-lines/7/resolve')
    && substr_count($html, 'name="choice"') === 3 && str_contains($html, 'Elevador &lt;manutenção&gt;') && str_contains($html, '(1 dia(s) de diferença)') && str_contains($html, '(mesmo dia)') && str_contains($html, 'value="ignore"')
    && !str_contains($html, 'value="ignore" checked'), 'AP-140: fila de pendentes e resolver com candidatos ou ignorar');
$html = $agreementPage('AP-140', 'pages/imports/import', ['lineDetail' => ['line' => $ofxData['lineDetail']['line'], 'candidates' => []], 'canResolveLines' => true] + $ofxData);
$assert(str_contains($html, 'Nenhum lançamento pago desta conta') && str_contains($html, 'value="ignore" checked') && str_contains($html, 'lance a conta paga nesta conta bancária'), 'AP-140: sem candidato, ignorar já marcado');
$html = $agreementPage('AP-140', 'pages/imports/import', ['canResolveLines' => false, 'pendingLines' => ['data' => [], 'total' => 0]] + $ofxData);
$assert(!str_contains($html, 'data-line-form') && str_contains($html, 'Sua conta pode ver, mas não resolver') && str_contains($html, 'Nenhuma linha pendente'), 'AP-140: sem permissão não resolve; fila vazia');
$html = $agreementPage('AP-140', 'pages/imports/import', $importData('ofx', ['importDetail' => $importRow(['kind' => 'ofx', 'kind_label' => 'Extrato bancário (OFX)', 'original_name' => 'extrato.ofx',
    'summary' => ['rows' => 3, 'errors' => 0, 'from' => '2026-11-01', 'to' => '2026-11-05', 'credits_cents' => 120000, 'debits_cents' => 45000, 'already' => 0,
        'balance_check' => ['bank_cents' => 500000, 'system_cents' => 480000, 'difference_cents' => 20000, 'matches' => false], 'applied' => ['lines' => 3, 'matched' => 1, 'unmatched' => 2, 'already' => 0]],
    'lines' => [$stmtLine(['status' => 'matched', 'entry_id' => 61, 'note' => null]), $stmtLine(['id' => 8, 'status' => 'unmatched']), $stmtLine(['id' => 9, 'status' => 'ignored', 'note' => 'Tarifa <já lançada>'])]])]), 'ofx-detail');
$assert(str_contains($html, 'O saldo do banco não confere') && str_contains($html, 'diferença R$ 200,00') && str_contains($html, '>Conferida</span>') && str_contains($html, '>Ignorada</span>') && str_contains($html, 'Tarifa &lt;já lançada&gt;')
    && str_contains($html, '?linha=8#conciliar') && str_contains($html, 'Conferidas: 1') && str_contains($html, '01/11/2026 a 05/11/2026'), 'AP-140: detalhe do extrato com saldo e linhas');
$assert($Imp::pages() == ['AP-140' => 'ofx', 'AP-144' => 'entries', 'AP-145' => 'unit_entries', 'AP-146' => 'payables', 'AP-143' => 'fractions', 'AP-142' => 'delinquents', 'AP-044' => 'readings']
        + (isset(Source\Services\Erp\Imports\Imports::KINDS['nfe']) ? ['AP-141' => 'nfe'] : [])
    && $Imp::templateHeader('fractions') === 'unidade;fracao' && $Imp::templateHeader('ofx') === null, 'importações: páginas e modelo');
foreach (array_keys($Imp::pages()) as $importPage) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($importPage, ['importReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($importPage, ['importReady' => true]) === 'pages/imports/import', "{$importPage}: tela própria só com dados");
}
foreach (['AP-026', 'AP-027', 'AP-028', 'AP-029'] as $shortcutPage) {
    $assert(Source\Controllers\Erp\Connect\Panel::ownTemplate($shortcutPage, ['shortcutReady' => false]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate($shortcutPage, ['shortcutReady' => true]) === $Shortcut::PAGES[$shortcutPage], "{$shortcutPage}: tela própria só com dados");
}

// Leituras do mês (AP-042, ADR 0045 e 0047) com dados reais.
$mrReading = static fn (array $over = []): array => $over + ['id' => 1, 'condominium_id' => 1, 'meter_id' => 1, 'kind' => 'agua', 'meter_number' => 'A-1', 'unit_id' => 9, 'unit_label' => 'Apto 101',
    'competence' => '2026-11', 'read_on' => '2026-11-05', 'previous_reading' => 100.0, 'reading' => 112.5, 'consumption' => 12.5, 'amount_cents' => 8750, 'tariff_valid_from' => '2026-01-01', 'entry_id' => null, 'billed' => false];
$mrMeters = [['id' => 1, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 9, 'number' => 'A-1', 'initial_reading' => 0.0],
    ['id' => 2, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 10, 'number' => 'A-<2>', 'initial_reading' => 50.0],
    ['id' => 3, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 11, 'number' => 'A-3', 'initial_reading' => 0.0],
    ['id' => 4, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'unit_id' => 12, 'number' => 'A-4', 'initial_reading' => 0.0]];
$mrSheet = Source\Controllers\Erp\Connect\Panel::readingSheet($mrMeters, [9 => 'Apto 101', 10 => 'Apto <102>', 11 => 'Apto 103', 12 => 'Apto 104'], [
    $mrReading(), $mrReading(['id' => 2, 'meter_id' => 3, 'unit_label' => 'Apto 103', 'entry_id' => 55, 'billed' => true, 'amount_cents' => 4000]),
    $mrReading(['id' => 3, 'meter_id' => 4, 'unit_label' => 'Apto 104', 'tariff_valid_from' => null, 'amount_cents' => 0]),
    $mrReading(['id' => 4, 'meter_id' => 2, 'competence' => '2026-10', 'reading' => 77.25]),
], '2026-11');
$mrBy = array_column($mrSheet, null, 'meter_id');
$assert(array_column($mrSheet, 'unit_label') === ['Apto 101', 'Apto 103', 'Apto 104', 'Apto <102>'] && $mrBy[2]['previous'] === 77.25 && $mrBy[2]['reading'] === null && $mrBy[1]['previous'] === 100.0 && $mrBy[3]['reading']['billed'] === true, 'AP-042: folha com a leitura anterior de cada relógio');
$mrPage = static fn (array $over = []): array => $over + ['meterSheet' => $mrSheet, 'meterCompetence' => '2026-11', 'meterKind' => 'agua', 'canManageReadings' => true];
$html = $agreementPage('AP-042', 'pages/operational/meter-readings', $mrPage(), 'manage');
$common($html, 'AP-042', 'Leituras');
$assert(str_contains($html, 'data-erp-meter-readings data-api="https://' . $smokeHost . '/api/v1/meter-readings"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'data-competence="2026-11" data-kind="agua"')
    && substr_count($html, 'name="reading"') === 3 && str_contains($html, 'Apto &lt;102&gt;') && str_contains($html, 'A-&lt;2&gt;') && str_contains($html, '12,500 m³') && str_contains($html, 'R$ 87,50')
    && str_contains($html, 'Cobrada') && str_contains($html, 'Sem tarifa') && str_contains($html, '1 leitura(s) sem tarifa vigente') && str_contains($html, 'Faturar 1 unidade(s)') && str_contains($html, 'Escolha um condomínio na barra superior para faturar'), 'AP-042: folha com leituras, cobrada travada, sem tarifa e faturar');
$html = $agreementPage('AP-042', 'pages/operational/meter-readings', $mrPage(['canManageReadings' => false]));
$assert(!str_contains($html, 'name="reading"') && !str_contains($html, 'data-meter-bill') && !str_contains($html, 'Salvar leituras') && str_contains($html, '112,500'), 'AP-042: sem erp.meter_readings.manage só consulta');
$html = $agreementPage('AP-042', 'pages/operational/meter-readings', $mrPage(['meterSheet' => [], 'meterKind' => 'energia']));
$assert(str_contains($html, 'Nenhum relógio de energia ativo') && str_contains($html, '0,000 kWh'), 'AP-042: sem relógios do tipo');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-042', ['meterSheet' => null]) === null && Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-042', ['meterSheet' => []]) === 'pages/operational/meter-readings', 'AP-042: sem permissão segue a demonstração');

// Disco Virtual (AP-122, ADR 0047 e 0049) com dados reais.
$diskRow = static fn (array $over = []): array => $over + ['id' => 7, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'folder' => 'atas', 'folder_label' => 'Atas',
    'title' => 'Ata <AGO> 2026', 'original_name' => 'ata-ago.pdf', 'mime' => 'application/pdf', 'size_bytes' => 1572864, 'sha256' => str_repeat('a', 64), 'uploaded_at' => '2026-11-09 10:00:00'];
$diskData = static fn (array $over = []): array => $over + ['diskFiles' => ['data' => [$diskRow(), $diskRow(['id' => 8, 'folder' => 'plantas', 'folder_label' => 'Plantas', 'title' => 'Planta', 'original_name' => 'planta.png', 'mime' => 'image/png', 'size_bytes' => 900])], 'total' => 2, 'page' => 1, 'per_page' => 50, 'pages' => 1],
    'diskFolder' => null, 'diskQuery' => '', 'diskCondominiums' => [['id' => 1, 'name' => 'Residencial Aurora'], ['id' => 2, 'name' => 'Vila <Sol>']], 'canManageFiles' => true];
$html = $agreementPage('AP-122', 'pages/documents/virtual-disk', $diskData(), 'manage');
$common($html, 'AP-122', 'Disco Virtual');
$assert(str_contains($html, 'data-erp-virtual-disk data-api="https://' . $smokeHost . '/api/v1/documents"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'data-disk-upload')
    && str_contains($html, 'Vila &lt;Sol&gt;') && str_contains($html, 'Ata &lt;AGO&gt; 2026') && str_contains($html, '1,5 MB') && str_contains($html, '900 B') && str_contains($html, '/api/v1/documents/7/download')
    && substr_count($html, 'data-disk-delete="') === 2 && str_contains($html, '<th>Pasta</th>') && str_contains($html, 'aria-current="page"'), 'AP-122: enviar, lista com baixar e excluir');
$html = $agreementPage('AP-122', 'pages/documents/virtual-disk', $diskData(['canManageFiles' => false, 'diskFolder' => 'atas', 'diskQuery' => 'ata']));
$assert(!str_contains($html, 'data-disk-upload') && !str_contains($html, 'data-disk-delete') && str_contains($html, '/api/v1/documents/7/download') && !str_contains($html, '<th>Pasta</th>') && str_contains($html, 'com "ata"') && str_contains($html, 'name="pasta" value="atas"'), 'AP-122: sem erp.documents.manage só consulta e baixa');
$html = $agreementPage('AP-122', 'pages/documents/virtual-disk', $diskData(['diskFiles' => ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 0]]));
$assert(str_contains($html, 'Pasta vazia'), 'AP-122: pasta vazia');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-122', ['diskFiles' => null]) === null && Source\Controllers\Erp\Connect\Panel::fileSize(1023) === '1023 B' && Source\Controllers\Erp\Connect\Panel::fileSize(1536) === '1,5 KB', 'AP-122: sem permissão segue a demonstração; tamanhos legíveis');

// Anexo do Disco Virtual nos cadastros AP-126 a 129 (ADR 0050 e 0052): formulário com o seletor.
$attachForm = static fn (array $attachment, array $values = []): string => $render('pages/registry/form', $layoutData->invoke($panel, $user, 'AP-127', [
    'title' => 'Nova cópia de cheque', 'definition' => Source\Services\Erp\Registry\Definitions::get('check_copies'), 'recordId' => null,
    'values' => $values + ['condominium_id' => 1], 'errors' => [], 'history' => [], 'condominiums' => [['id' => 1, 'name' => 'Residencial Aurora'], ['id' => 2, 'name' => 'Vila Sol']],
    'units' => [], 'refs' => [], 'canManage' => true,
    'registryAttachment' => $attachment + ['field' => 'document_id', 'file' => null, 'canBrowse' => true, 'canUpload' => true],
], 'Nova cópia de cheque'));
$html = $attachForm(['file' => ['id' => 12, 'title' => 'Cheque <123>', 'original_name' => 'cheque.jpg']], ['document_id' => 12]);
$save('page-ap-127-attach', $html);
$assert(str_contains($html, 'data-erp-attachment data-api="https://' . $smokeHost . '/api/v1/documents"') && str_contains($html, 'Cheque &lt;123&gt;') && str_contains($html, '/api/v1/documents/12/download')
    && str_contains($html, 'data-attachment-send') && str_contains($html, '<label for="f-document_id-pick">') && substr_count($html, 'data-erp-attachment ') === 1, 'AP-127: anexo com seletor e envio do Disco Virtual');
$html = $attachForm(['canBrowse' => false, 'canUpload' => false]);
$assert(!str_contains($html, 'data-attachment-open') && str_contains($html, 'Nenhum arquivo anexado'), 'AP-127: sem acesso ao Disco Virtual não oferece escolher');

// Agendamentos Pendentes (AP-114, ADR 0051 e 0070): reservas aguardando aprovação.
$pendingRow = static fn (array $over = []): array => $over + ['id' => 31, 'condominium_id' => 1, 'condominium_name' => 'Residencial Aurora', 'common_area_id' => 4, 'unit_id' => 9,
    'resident_name' => 'Ana <Souza>', 'reserved_on' => '2026-11-14', 'starts_time' => '14:00', 'ends_time' => '18:00', 'fee_cents' => 15000, 'notes' => null, 'status' => 'active'];
$pendingData = static fn (array $over = []): array => $over + ['pendingReservations' => ['data' => [$pendingRow(), $pendingRow(['id' => 32, 'starts_time' => null, 'ends_time' => null, 'fee_cents' => null, 'common_area_id' => 99])], 'total' => 2, 'page' => 1, 'per_page' => 50, 'pages' => 1],
    'pendingAreas' => [4 => 'Salão de festas'], 'pendingUnits' => [9 => 'Bloco A 101'], 'canDecideReservations' => true, 'flash' => null];
$html = $agreementPage('AP-114', 'pages/operational/pending-reservations', $pendingData(), 'manage');
$common($html, 'AP-114', 'Agendamentos Pendentes');
$assert(str_contains($html, 'Salão de festas') && str_contains($html, 'Área nº 99') && str_contains($html, 'Bloco A 101') && str_contains($html, 'Ana &lt;Souza&gt;') && str_contains($html, '14:00 às 18:00')
    && str_contains($html, 'Dia inteiro') && str_contains($html, 'R$ 150,00') && str_contains($html, '/operacional/agendamentos-pendentes/31/confirmar') && str_contains($html, '/operacional/agendamentos-pendentes/32/recusar')
    && substr_count($html, 'data-erp-confirm=') === 2 && str_contains($html, '/operacional/reservas-areas-comuns/31') && str_contains($html, 'sábado'), 'AP-114: pendentes com confirmar e recusar');
$html = $agreementPage('AP-114', 'pages/operational/pending-reservations', $pendingData(['canDecideReservations' => false]));
$assert(!str_contains($html, '/confirmar') && !str_contains($html, '/recusar') && str_contains($html, '/operacional/reservas-areas-comuns/31'), 'AP-114: sem manage só consulta');
$html = $agreementPage('AP-114', 'pages/operational/pending-reservations', $pendingData(['pendingReservations' => ['data' => [], 'total' => 0, 'page' => 1, 'per_page' => 50, 'pages' => 0]]));
$assert(str_contains($html, 'Nenhuma reserva pendente'), 'AP-114: sem pendentes');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-114', ['pendingReservations' => null]) === null, 'AP-114: sem dados segue a demonstração');

// Integrações (AP-171, ADR 0161): só leitura, com o bloco do antivírus.
$integrationData = static fn (array $av): array => ['integrations' => [
    'bank' => ['adapters' => [['adapter' => 'sandbox', 'available' => true, 'production' => false]], 'note' => 'Banco real só depois de homologação.'],
    'mail' => ['mode' => 'sandbox', 'sandbox_inbox_configured' => true, 'reaches_residents' => false],
    'price_indexes' => [['code' => 'IPCA', 'last_competence' => '2026-10', 'state' => 'ok'], ['code' => 'IGPM', 'last_competence' => null, 'state' => 'missing']],
    'outbox' => ['pending' => 3, 'failed' => 1],
    'antivirus' => $av,
]];
$html = $agreementPage('AP-171', 'pages/settings/integrations', $integrationData(Source\Services\Erp\IntegrationStatus::antivirus('off', false, ['clean' => 2, 'unscanned' => 7, 'error' => 1])), 'antivirus');
$common($html, 'AP-171', 'Integrações');
$assert(str_contains($html, 'data-integrations-antivirus') && str_contains($html, 'Antivírus desligado') && str_contains($html, '8 arquivo(s) aguardando reverificação')
    && substr_count($html, 'data-scan-status=') === 4 && str_contains($html, 'Nunca carregado') && str_contains($html, '/configuracoes/fila-de-avisos'), 'AP-171: antivírus, índices e fila');
$html = $agreementPage('AP-171', 'pages/settings/integrations', $integrationData(Source\Services\Erp\IntegrationStatus::antivirus('enforce', false, [])));
$assert(str_contains($html, 'Antivírus ligado sem endereço configurado'), 'AP-171: ligado sem antivírus configurado avisa');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-171', ['integrations' => null]) === null && Source\Controllers\Erp\Connect\Panel::pagePermissions()['AP-171'] === 'erp.settings.view', 'AP-171: sem dados segue a demonstração; exige erp.settings.view');

// Ocorrência (AP-112): chamado no Help Desk no formulário.
$occurrenceForm = static fn (array $values, bool $canManage = true): string => $render('pages/registry/form', $layoutData->invoke($panel, $user, 'AP-112', [
    'title' => 'Vazamento', 'definition' => Source\Services\Erp\Registry\Definitions::get('occurrences'), 'recordId' => 5,
    'values' => $values + ['id' => 5, 'condominium_id' => 1, 'subject' => 'Vazamento', 'priority' => 'alta', 'occurred_on' => '2026-11-08', 'description' => 'Vazamento na garagem', 'status' => 'active'],
    'errors' => [], 'history' => [], 'condominiums' => [['id' => 1, 'name' => 'Residencial Aurora']], 'units' => [], 'refs' => [], 'canManage' => $canManage,
    'registryAttachment' => null, 'occurrenceTicket' => Source\Controllers\Erp\Connect\Registry::ticketData(5, $values, $canManage),
], 'Vazamento'));
$html = $occurrenceForm(['ticket_id' => null]);
$save('page-ap-112-ticket', $html);
$assert(str_contains($html, 'data-erp-occurrence-ticket data-api="https://' . $smokeHost . '/api/v1/occurrences/5/ticket"') && str_contains($html, 'data-ticket-open') && str_contains($html, 'Operacional · Ocorrências'), 'AP-112: abrir chamado no Help Desk');
$html = $occurrenceForm(['ticket_id' => '77']);
$assert(!str_contains($html, 'data-ticket-open') && str_contains($html, 'nº 77') && str_contains($html, '/operacional/chamados/77'), 'AP-112: chamado aberto com link');
$html = $occurrenceForm(['ticket_id' => null], false);
$assert(!str_contains($html, 'data-ticket-open') && str_contains($html, 'Nenhum chamado aberto'), 'AP-112: sem manage não abre chamado');

// Dashboard com cards e alertas reais (ADR 0053 e 0070).
$operationalCards = ['maintenances_late' => 2, 'maintenances_week' => 1, 'insurances_renewal' => 0, 'insurances_expired' => 1, 'contracts_renewal' => 0, 'contracts_expired' => 0,
    'reservations_pending' => 3, 'occurrences_open' => 4, 'deliveries_waiting' => 0, 'receivables_overdue_cents' => 1234567, 'receivables_week_cents' => 50000, 'payables_week_cents' => 0, 'waitlist_to_call' => 1];
$operationalAlerts = [['kind' => 'maintenances_late', 'severity' => 'danger', 'count' => 2, 'message' => 'Manutenções atrasadas', 'route' => '/operacional/manutencoes'],
    ['kind' => 'reservations_pending', 'severity' => 'info', 'count' => 3, 'message' => 'Reservas <aguardando> aprovação', 'route' => '/operacional/agendamentos-pendentes']];
$data = $layoutData->invoke($panel, $user, 'AP-001', ['dashboard' => Source\Services\Erp\DemoData::get('dashboard'), 'operational' => ['as_of' => '2026-11-09', 'condominium_id' => null, 'cards' => $operationalCards, 'alerts' => $operationalAlerts]]);
$html = $render('pages/dashboard/index', $data);
$save('dashboard-real', $html);
$assert(str_contains($html, 'data-erp-dashboard-real') && str_contains($html, 'R$ 12.345,67') && str_contains($html, 'Vence em 7 dias: R$ 500,00') && str_contains($html, '1 prevista para os próximos 7 dias')
    && str_contains($html, '3 reservas aguardando aprovação') && !str_contains($html, 'R$ 1.284.530') && str_contains($html, 'Reservas &lt;aguardando&gt; aprovação')
    && str_contains($html, '/operacional/agendamentos-pendentes') && substr_count($html, 'Alertas</h2>') === 1, 'dashboard: cards e alertas reais no lugar da demonstração');
putenv('ERP_DEMO_DATA=false');
$data = $layoutData->invoke($panel, $user, 'AP-001', ['dashboard' => Source\Services\Erp\DemoData::get('dashboard'), 'operational' => ['as_of' => '2026-11-09', 'condominium_id' => null, 'cards' => $operationalCards, 'alerts' => []]]);
$html = $render('pages/dashboard/index', $data);
$assert(str_contains($html, 'data-erp-dashboard-real') && str_contains($html, 'Nada pendente') && !str_contains($html, 'Indicadores ainda indisponíveis'), 'dashboard: sem demonstração mostra os dados reais');
putenv('ERP_DEMO_DATA=true');

// Continuações do Operacional no formulário: chamar lista de espera e gerar a próxima manutenção.
$actionForm = static fn (string $slug, string $page, array $values): string => $render('pages/registry/form', $layoutData->invoke($panel, $user, $page, [
    'title' => 'Registro', 'definition' => Source\Services\Erp\Registry\Definitions::get($slug), 'recordId' => 8, 'values' => $values + ['id' => 8, 'condominium_id' => 1],
    'errors' => [], 'history' => [], 'condominiums' => [['id' => 1, 'name' => 'Residencial Aurora']], 'units' => [], 'refs' => [], 'canManage' => true,
    'registryAttachment' => null, 'occurrenceTicket' => null, 'recordAction' => Source\Controllers\Erp\Connect\Registry::recordAction($slug, 8, $values),
], 'Registro'));
$html = $actionForm('reservations', 'AP-113', ['status' => 'inactive']);
$save('page-ap-113-waitlist', $html);
$assert(str_contains($html, 'data-erp-record-action data-kind="waitlist" data-api="https://' . $smokeHost . '/api/v1/reservations/8/call-waitlist"') && str_contains($html, 'Chamar lista de espera'), 'AP-113: reserva cancelada chama a lista de espera');
$assert(!str_contains($actionForm('reservations', 'AP-113', ['status' => 'confirmed']), 'data-erp-record-action'), 'AP-113: reserva válida não chama a lista');
$html = $actionForm('maintenances', 'AP-110', ['status' => 'done', 'frequency' => 'mensal']);
$save('page-ap-110-next', $html);
$assert(str_contains($html, 'data-kind="next" data-api="https://' . $smokeHost . '/api/v1/maintenances/8/next"') && str_contains($html, 'Gerar próxima'), 'AP-110: manutenção concluída recorrente gera a próxima');
$assert(!str_contains($actionForm('maintenances', 'AP-110', ['status' => 'done', 'frequency' => 'unica']), 'data-erp-record-action') && !str_contains($actionForm('maintenances', 'AP-110', ['status' => 'active', 'frequency' => 'mensal']), 'data-erp-record-action'), 'AP-110: única ou não concluída não gera próxima');

// Painéis do condomínio (AP-002) e da unidade (AP-003), ADR 0054 e 0071.
$condoPanel = ['condominium' => ['id' => 7, 'name' => 'Residencial <Aurora>', 'status' => 'active'], 'as_of' => '2026-11-09',
    'occupancy' => ['units' => 40, 'occupied' => 36, 'vacant' => 4, 'rate' => 90.0],
    'finance' => ['bank_balance_cents' => 4567890, 'bank_accounts' => [['id' => 1, 'name' => 'Itaú <principal>', 'is_main' => true, 'received_cents' => 100000, 'paid_cents' => 50000, 'balance_cents' => 4567890]],
        'delinquency_cents' => 300000, 'delinquency_charges_cents' => 12345, 'delinquent_units' => 3, 'buckets' => ['1-30' => 100000, '31-60' => 0, '61-90' => 50000, '90+' => 150000],
        'competence' => '2026-11', 'closing_status' => 'open', 'competence_result_cents' => -2000],
    'operations' => ['as_of' => '2026-11-09', 'condominium_id' => 7, 'cards' => $operationalCards, 'alerts' => $operationalAlerts]];
$html = $agreementPage('AP-002', 'pages/condominiums/panel', ['condoPanelReady' => true, 'condoPanel' => $condoPanel, 'condoPanelError' => null], 'real');
$common($html, 'AP-002', 'Residencial <Aurora>');
$assert(str_contains($html, 'data-erp-condo-panel') && str_contains($html, '36 ocupadas · 4 vagas (90,0%)') && str_contains($html, 'R$ 45.678,90') && str_contains($html, 'Itaú &lt;principal&gt;')
    && str_contains($html, 'encargos R$ 123,45') && str_contains($html, 'Resultado de 11/2026') && str_contains($html, 'Competência Aberta') && str_contains($html, 'Acima de 90 dias')
    && str_contains($html, 'Manutenções atrasadas') && str_contains($html, '/condominios/7'), 'AP-002: painel com ocupação, financeiro e alertas');
$html = $agreementPage('AP-002', 'pages/condominiums/panel', ['condoPanelReady' => true, 'condoPanel' => null, 'condoPanelError' => null]);
$assert(str_contains($html, 'Escolha um condomínio') && str_contains($html, '/condominios/painel-do-condominio'), 'AP-002: em todos os condomínios pede a escolha');
$assert(Source\Controllers\Erp\Connect\Panel::ownTemplate('AP-002', ['condoPanelReady' => false]) === null, 'AP-002: sem permissão ou banco segue a demonstração');

$unitPanel = ['unit' => ['id' => 9, 'condominium_id' => 7, 'condominium_name' => 'Residencial Aurora', 'label' => 'A-101', 'block' => 'A', 'number' => '101', 'type' => 'apartamento', 'ideal_fraction' => 0.025], 'as_of' => '2026-11-09',
    'people' => [['id' => 1, 'name' => 'Ana <Souza>', 'relationship' => 'owner', 'phone' => '(11) 99999-0000', 'email' => 'ana@example.com']],
    'finance' => ['overdue' => [['id' => 3, 'description' => 'Taxa <outubro>', 'competence' => '2026-10', 'due_on' => '2026-10-10', 'amount_cents' => 80000, 'days_late' => 30, 'bucket' => '1-30']],
        'overdue_cents' => 80000, 'charges_cents' => 2400, 'max_days_late' => 30,
        'upcoming' => [['id' => 4, 'description' => 'Taxa novembro', 'competence' => '2026-11', 'due_on' => '2026-11-10', 'amount_cents' => 80000]],
        'agreements' => [['id' => 2, 'status' => 'breached', 'total_cents' => 150000, 'installments' => 3, 'first_due_on' => '2026-08-10']]],
    'documents' => [['id' => 5, 'kind_label' => 'Certidão de débitos', 'verification_display' => 'ABCD-EFGH', 'issued_at' => '2026-11-01 10:00:00', 'status' => 'issued']],
    'operations' => ['deliveries_waiting' => 1, 'reservations_upcoming' => 2, 'occurrences_open' => 1, 'vehicles' => 1, 'pets' => 0]];
$unitData = static fn (array $over = []): array => $over + ['unitPanelReady' => true, 'unitPanel' => $unitPanel, 'unitPanelUnits' => [['id' => 9, 'condominium_id' => 7, 'label' => 'A-101'], ['id' => 10, 'condominium_id' => 7, 'label' => 'A-102']], 'unitPanelSelected' => 9, 'unitPanelError' => null];
$html = $agreementPage('AP-003', 'pages/condominiums/unit-panel', $unitData(), 'real');
$common($html, 'AP-003', 'Unidade A-101');
$assert(str_contains($html, 'data-erp-unit-panel') && str_contains($html, 'R$ 824,00') && str_contains($html, 'Taxa &lt;outubro&gt;') && str_contains($html, 'Ana &lt;Souza&gt;') && str_contains($html, 'Proprietário')
    && str_contains($html, 'Quebrado') && str_contains($html, 'ABCD-EFGH') && str_contains($html, '<option value="9" selected>') && str_contains($html, 'data-erp-autosubmit')
    && str_contains($html, '1 entrega(s) · 1 ocorrência(s) · 2 reserva(s)'), 'AP-003: painel da unidade');
$html = $agreementPage('AP-003', 'pages/condominiums/unit-panel', $unitData(['unitPanel' => null, 'unitPanelSelected' => null]));
$assert(str_contains($html, 'Escolha uma unidade') && !str_contains($html, 'data-erp-unit-panel'), 'AP-003: sem unidade escolhida pede a escolha');
$html = $agreementPage('AP-003', 'pages/condominiums/unit-panel', $unitData(['unitPanel' => null, 'unitPanelSelected' => 99, 'unitPanelError' => 'Unidade não encontrada no seu escopo de condomínios.']));
$assert(str_contains($html, 'Unidade não encontrada') && !str_contains($html, 'Escolha uma unidade'), 'AP-003: unidade fora do escopo');
$html = $agreementPage('AP-003', 'pages/condominiums/unit-panel', $unitData(['unitPanel' => null, 'unitPanelUnits' => [], 'unitPanelSelected' => null]));
$assert(str_contains($html, 'Nenhuma unidade cadastrada'), 'AP-003: sem unidades');

// Cadastro rápido de unidades e fração ideal/área em grade (ADR 0140).
$html = $render('pages/condominiums/unit-batch', $layoutData->invoke($panel, $user, 'AP-006', ['batchCondominiums' => [['id' => 7, 'name' => 'Residencial <Exemplo>']], 'batchSelected' => 7, 'batchTypes' => Source\Services\Erp\UnitBatch::TYPES], 'Cadastro rápido de unidades'));
$save('unit-batch', $html);
$common($html, 'cadastro rápido', 'Cadastro rápido de unidades');
$assert(str_contains($html, 'data-erp-unit-batch data-api="https://' . $smokeHost . '/api/v1/unit-batches"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, '<option value="7" selected>Residencial &lt;Exemplo&gt;</option>')
    && str_contains($html, 'name="units_per_floor"') && str_contains($html, 'data-unit-batch-preview hidden'), 'cadastro rápido: formulário, API e CSRF');
$html = $render('pages/condominiums/unit-batch', $layoutData->invoke($panel, $user, 'AP-006', ['batchCondominiums' => [], 'batchSelected' => null, 'batchTypes' => Source\Services\Erp\UnitBatch::TYPES], 'Cadastro rápido de unidades'));
$assert(str_contains($html, 'Nenhum condomínio no seu acesso') && !str_contains($html, 'data-unit-batch-form'), 'cadastro rápido: sem condomínio');
$measureUnit = static fn (int $id, string $label, ?float $f, ?float $a): array => ['id' => $id, 'label' => $label, 'block' => null, 'number' => $label, 'floor' => '1', 'type' => 'apartamento', 'ideal_fraction' => $f, 'private_area' => $a];
$measures = ['condominium_id' => 7, 'units' => [$measureUnit(1, '101', 0.25, 50.0), $measureUnit(2, '<102>', null, 72.5), $measureUnit(3, '103', null, null)], 'fraction_sum' => 0.25, 'area_sum' => 122.5, 'missing_fraction' => 2, 'missing_area' => 1, 'complete' => false];
$measuresData = static fn (array $over = []): array => $over + ['measuresReady' => true, 'measures' => $measures, 'measuresError' => null, 'canManageMeasures' => true];
Source\Support\Erp\CondominiumContext::use([['id' => 7, 'name' => 'Residencial Exemplo']], 7);
$html = $agreementPage('AP-167', 'pages/condominiums/unit-measures', $measuresData(), 'real');
$assert(str_contains($html, 'data-erp-unit-measures data-api="https://' . $smokeHost . '/api/v1/unit-measures" data-condominium="7"') && (bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, '&lt;102&gt;') && !str_contains($html, '<102>')
    && substr_count($html, 'name="ideal_fraction"') === 3 && str_contains($html, 'value="0,250000"') && str_contains($html, 'value="72,50"') && str_contains($html, 'data-unit-measures-distribute="area"') && str_contains($html, '25,0000%'), 'AP-167: grade editável, escapada, com API e CSRF');
$html = $agreementPage('AP-167', 'pages/condominiums/unit-measures', $measuresData(['canManageMeasures' => false]));
$assert(!str_contains($html, 'name="ideal_fraction"') && !str_contains($html, 'data-unit-measures-distribute') && str_contains($html, '0,250000'), 'AP-167: só leitura sem permissão de alterar');
$html = $agreementPage('AP-167', 'pages/condominiums/unit-measures', $measuresData(['measures' => ['units' => []] + $measures]));
$assert(str_contains($html, 'Nenhuma unidade ativa') && str_contains($html, 'cadastro rápido'), 'AP-167: sem unidades sugere o cadastro rápido');
Source\Support\Erp\CondominiumContext::reset();
$html = $agreementPage('AP-167', 'pages/condominiums/unit-measures', $measuresData(['measures' => null]));
$assert(str_contains($html, 'Escolha um condomínio'), 'AP-167: sem condomínio na barra superior');

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
$idBatch = array_search((erp_host() ? '' : '/erp') . '/condominios/unidades/cadastro-rapido', array_map(static fn (string $p): string => substr($p, 4), $route->paths), true);
$idUnitShow = array_search((erp_host() ? '' : '/erp') . '/condominios/unidades/{id}', array_map(static fn (string $p): string => substr($p, 4), $route->paths), true);
$assert($idBatch !== false && $idUnitShow !== false && $idBatch < $idUnitShow, 'cadastro rápido registrado antes de /condominios/unidades/{id}');
echo 'Rotas registradas: ' . count($route->paths) . "\n";

ksort($kinds);
echo "Páginas por tipo: " . json_encode($kinds, JSON_UNESCAPED_UNICODE) . "\n";
printf("%d verificações, %d falhas\n", $checks, count($failures));
foreach ($failures as $f) echo "FALHA: $f\n";
exit($failures ? 1 : 0);
