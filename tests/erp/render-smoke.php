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
$psr4 = ['Source\\' => $root . '/source/', 'MovesCode\\View\\' => $vendor . '/movescode/view/src/'];
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
$assert(str_contains($html, 'erp-bars') && str_contains($html, 'erp-donut'), 'dashboard: gráficos');
$assert(str_contains($html, 'data-erp-table'), 'dashboard: tabela paginável');
$assert(str_contains($html, '<dialog class="erp-modal"'), 'dashboard: modal');
$assert(str_contains($html, 'Dados de demonstração'), 'dashboard: aviso de demonstração');
$save('dashboard', $html);

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
        $assert(str_contains($show, 'Inativo') && str_contains($show, 'role="tablist"') && str_contains($show, '<a href="' . $erpRoot . '/condominios">Condomínios</a>'), 'AP-004: detalhe com abas e trilha de volta');
        $assert(!str_contains($html, '/condominios/novo') && !str_contains($show, '/editar') && !str_contains($html . $show, 'data-erp-pending'), 'AP-004: sem canManage não há Novo/Editar nem botões pendentes');
        // Quem administra a carteira: Novo, Editar e o formulário com CSRF.
        $managed = $render('pages/condominiums/index', $layoutData->invoke($panel, $user, 'AP-004', ['condominiums' => [], 'canManage' => true, 'flash' => null], 'Condomínios'));
        $assert(str_contains($managed, $erpRoot . '/condominios/novo') && str_contains($managed, 'Nenhum condomínio cadastrado'), 'AP-004: lista vazia oferece Novo condomínio a quem administra');
        $showManaged = $render('pages/condominiums/show', $layoutData->invoke($panel, $user, 'AP-004', ['condominium' => ['id' => 9, 'name' => 'Edifício Exemplo', 'document' => null, 'status' => 'inactive'], 'canManage' => true, 'flash' => null], 'Edifício Exemplo'));
        $assert(str_contains($showManaged, $erpRoot . '/condominios/9/editar'), 'AP-004: detalhe com Editar para quem administra');
        $statuses = ['active' => 'Ativo', 'inactive' => 'Inativo'];
        $newForm = $render('pages/condominiums/form', $layoutData->invoke($panel, $user, 'AP-004', ['recordId' => null, 'values' => ['status' => 'active'], 'errors' => [], 'statuses' => $statuses], 'Novo condomínio'));
        $assert(str_contains($newForm, 'action="' . $erpRoot . '/condominios"') && str_contains($newForm, "name='csrf'") && str_contains($newForm, 'name="name"') && str_contains($newForm, 'name="document"') && str_contains($newForm, 'name="status"'), 'AP-004: formulário novo com CSRF e campos');
        $editForm = $render('pages/condominiums/form', $layoutData->invoke($panel, $user, 'AP-004', ['recordId' => 9, 'values' => ['name' => '<i>X</i>', 'document' => '123', 'status' => 'inactive'], 'errors' => ['document' => 'CNPJ inválido.'], 'statuses' => $statuses], '<i>X</i>'));
        $assert(str_contains($editForm, 'action="' . $erpRoot . '/condominios/9"') && str_contains($editForm, '&lt;i&gt;X') && !str_contains($editForm, '<i>X</i>') && str_contains($editForm, 'CNPJ inválido.') && str_contains($editForm, 'aria-invalid="true"'), 'AP-004: edição escapada e com erro por campo');
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
    $assert((bool)preg_match('~<a href="' . preg_quote($erpRoot . $page['route'], '~') . '" aria-current="page"~', $html) || $page['group'] === 'relatorios' || $page['group'] === 'operacional' || $page['group'] === 'configuracoes', "$id: link ativo na sidebar");
    preg_match_all('~href="' . preg_quote('https://' . $smokeHost, '~') . '(' . preg_quote($erpPrefix, '~') . '/[^"#]*)~', $html, $m);
    foreach ($m[1] as $h) $hrefs[$h] = true;
    $save('page-' . strtolower($id), $html);
}

// Links internos apontam para rotas registradas
$registered = [$erpPrefix . '/dashboard' => true, $erpPrefix . '/configuracoes' => true, $erpPrefix . '/login' => true, $erpPrefix . '/sair' => true];
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
$data = $layoutData->invoke($panel, $user, null, ['recoveryCodes' => ['AAAA-BBBB', 'CCCC-DDDD']], 'Códigos de recuperação');
$assert(str_contains($render('pages/auth/recovery-codes', $data), 'CCCC-DDDD'), 'códigos de recuperação');

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
