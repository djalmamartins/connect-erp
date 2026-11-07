<?php

declare(strict_types=1);

/**
 * Smoke de renderização do tema ERP sem banco: monta os dados da moldura pelo próprio
 * Panel::layoutData() e renderiza dashboard, visões gerais, todas as páginas do catálogo,
 * estados e o login (view do Studio). Qualquer aviso PHP vira falha.
 *
 * Uso: VENDOR=/caminho/vendor php tests/erp/render-smoke.php [--out=dir]
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

$_SERVER['HTTP_HOST'] = 'www.connect.lab';
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

$out = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
}
if ($out) @mkdir($out, 0777, true);

putenv('ERP_DEMO_DATA=' . (getenv('ERP_DEMO_DATA') !== false ? getenv('ERP_DEMO_DATA') : 'true'));

$user = new Source\Models\User();
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
    foreach ($pages as $p) $assert(str_contains($html, 'href="https://www.connect.lab/erp' . $p['route'] . '"'), "hub $key: link {$p['route']}");
    $save('hub-' . $key, $html);
}

// Todas as páginas do catálogo
$hrefs = [];
foreach ($catalog['pages'] as $id => $page) {
    if ($id === 'AP-001') continue;
    $data = $layoutData->invoke($panel, $user, $id);
    $html = $render('pages/system/pending', $data);
    $common($html, $id, $page['name']);
    $assert(str_contains($html, $id . ' · especificação'), "$id: rastreabilidade");
    $assert((bool)preg_match('~<a href="https://www.connect.lab/erp' . preg_quote($page['route'], '~') . '" aria-current="page"~', $html) || $page['group'] === 'relatorios' || $page['group'] === 'operacional' || $page['group'] === 'configuracoes', "$id: link ativo na sidebar");
    preg_match_all('~href="https://www\.connect\.lab(/erp[^"#]*)~', $html, $m);
    foreach ($m[1] as $h) $hrefs[$h] = true;
    $save('page-' . strtolower($id), $html);
}

// Links internos apontam para rotas registradas
$registered = ['/erp/dashboard' => true, '/erp/configuracoes' => true, '/erp/login' => true, '/erp/sair' => true];
foreach ($catalog['pages'] as $p) $registered['/erp' . $p['route']] = true;
foreach ($catalog['groups'] as $g) if ($g['prefix'] !== '') $registered['/erp' . $g['prefix']] = true;
foreach (array_keys($hrefs) as $h) $assert(isset($registered[$h]), "link sem rota: $h");

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

// Login (view do Studio reaproveitada)
$studio = static fn (): Source\Core\View => new Source\Core\View($root . '/container/cms/studio/v1/', 'php');
$html = $studio()->render('components/login/login', ['head' => '<title>Entrar</title>', 'cookie' => null, 'adminBase' => '/erp', 'loginEnvironment' => 'erp', 'forgotUrl' => '/erp/recuperar-senha']);
$assert(str_contains($html, 'action="https://www.connect.lab/erp/login"'), 'login: envia para /erp/login');
$assert(str_contains($html, 'CONNECT ERP') && str_contains($html, 'Esqueci minha senha'), 'login: identidade ERP e recuperação');
$assert((bool)preg_match('~name=[\x27"]csrf[\x27"]~', $html) && str_contains($html, 'type="password"'), 'login: CSRF e senha');
$save('login', $html);
$html = $studio()->render('components/login/login', ['head' => '', 'cookie' => null, 'adminBase' => '/studio', 'loginEnvironment' => 'studio']);
$assert(str_contains($html, 'MOVESOS') && str_contains($html, 'Área restrita'), 'login do Studio inalterado');

printf("%d verificações, %d falhas\n", $checks, count($failures));
foreach ($failures as $f) echo "FALHA: $f\n";
exit($failures ? 1 : 0);
