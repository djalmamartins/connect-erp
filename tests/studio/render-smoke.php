<?php

declare(strict_types=1);

/**
 * Smoke de renderização da moldura do Studio e do Help Desk (layout igual ao do ERP, ADR 0026),
 * sem banco: renderiza a lista de páginas real (components/pages/home) dentro de layouts/studio
 * nos ambientes /studio e /helpdesk e no host próprio studio.connect.lab. Qualquer aviso PHP vira falha.
 *
 * Uso: [VENDOR=/caminho/vendor] php tests/studio/render-smoke.php [--out=dir]
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

foreach ([
    'CONF_VIEW_ERP' => 'connect', 'CONF_VIEW_THEME' => 'default', 'CONF_VIEW_EXT' => 'php', 'CONF_VIEW_PATH' => $root,
    'CONF_URL_LOCAL' => 'https://www.connect.lab', 'CONF_URL_SSL' => true, 'CONF_URL_BASE' => 'https://www.connect.lab', 'CONF_URL_TEST' => 'https://www.connect.lab',
    'CONF_SITE_NAME' => 'Connect', 'CONF_SITE_DESC' => 'Connect', 'CONF_SITE_LANG' => 'pt_BR', 'CONF_SITE_DOMAIN' => 'connect.lab',
    'CONF_UPLOAD_DIR' => 'storage', 'VERSION_STUDIO' => '1.0.0',
    'CONF_SES_PATH' => sys_get_temp_dir() . '/studio-smoke-sessions/',
] as $name => $value) {
    if (!defined($name)) define($name, $value);
}
@mkdir(CONF_SES_PATH, 0700, true);
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_save_path(CONF_SES_PATH);
    @session_start();
}
require_once $root . '/source/Boot/Helpers.php';

$out = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--out=')) $out = substr($arg, 6);
}
if ($out) @mkdir($out, 0777, true);

$user = new class extends Source\Models\User {
    public array $granted = ['*'];
    public function can(string $permission): bool { return in_array('*', $this->granted, true) || in_array($permission, $this->granted, true); }
    public function photo(): ?string { return null; }
};
foreach (['id' => 1, 'first_name' => 'Ana', 'last_name' => 'Exemplo', 'email' => 'ana@example.invalid', 'level' => 5] as $k => $v) {
    $user->$k = $v;
}
$pages = [
    (object)['id' => 1, 'title' => 'Quem somos', 'uri' => 'quem-somos', 'status' => 'post', 'views' => 42, 'updated_at' => '2026-11-08 10:00:00'],
    (object)['id' => 2, 'title' => 'Serviços <para condomínios>', 'uri' => 'servicos', 'status' => 'draft', 'views' => 0, 'updated_at' => '2026-11-07 09:30:00'],
];
$theme = $root . '/container/cms/studio/v1';

$checks = 0; $failures = [];
$assert = static function (bool $ok, string $label) use (&$checks, &$failures): void {
    $checks++;
    if (!$ok) $failures[] = $label;
};

$cases = [
    'studio' => ['www.connect.lab', '/studio/pages', 'Studio'],
    'helpdesk' => ['www.connect.lab', '/helpdesk/tickets', 'Help Desk'],
    'studio-host' => ['studio.connect.lab', '/studio/pages', 'Studio'],
];
foreach ($cases as $label => [$host, $path, $environment]) {
    $_SERVER['HTTP_HOST'] = $host;
    $_SERVER['HTTPS'] = 'on';
    $_SERVER['SCRIPT_NAME'] = '/index.php';
    $_SERVER['REQUEST_URI'] = $path;
    $html = (new Source\Core\View($theme, 'php'))->render('components/pages/home', [
        'head' => '<title>Páginas - Connect</title>', 'title' => 'Páginas', 'app' => 'pages', 'user' => $user,
        'currentVersion' => '1.0.0', 'adminBase' => $environment === 'Help Desk' ? '/helpdesk' : '/studio', 'pages' => $pages,
    ]);
    if ($out) file_put_contents("$out/$label.html", $html);

    $assert(str_contains($html, 'class="studio-body studio-v2 studio-erp'), "$label: corpo com a moldura do ERP");
    $assert(str_contains($html, '/assets/css/connect-shell.css?v='), "$label: camada visual do ERP carregada");
    $assert(str_contains($html, 'class="studio-erp-brand-logo"'), "$label: logotipo Connect na sidebar");
    $type = $environment === 'Help Desk' ? 'Atendimento' : 'CMS';
    $assert(str_contains($html, "<small>$type</small><strong>Moves $environment</strong>"), "$label: cabeçalho '$type / Moves $environment'");
    $assert(!str_contains($html, 'MOVES<small>'), "$label: sem a marca antiga");
    $assert(!str_contains($html, 'studio-theme-toggle'), "$label: sem alternância de tema (o ERP não tem)");
    $assert(str_contains($html, 'data-studio-collapse'), "$label: botão de recolher na sidebar");
    $assert(str_contains($html, 'class="studio-erp-title"'), "$label: título e trilha na navbar");
    $assert(substr_count($html, '<main') === 1, "$label: um <main>");
    $assert(str_contains($html, 'Serviços &lt;para condomínios&gt;'), "$label: conteúdo escapado");
    $activeHref = 'https://' . $host . ($environment === 'Help Desk' ? '/helpdesk/tickets' : '/studio/pages');
    if ($label !== 'helpdesk') {
        $assert(str_contains($html, 'href="' . $activeHref . '" aria-current="page"'), "$label: item ativo no menu");
        $assert((bool)preg_match('~<details class="studio-erp-nav-group" open>\s*<summary class="is-active"[^>]*>.*?Conteúdo~s', $html), "$label: grupo da página atual aberto");
    }
    $assert(substr_count($html, 'class="studio-erp-nav-group"') >= 2, "$label: grupos do menu em <details>, como no ERP");
    $assert(str_contains($html, 'data-environment="' . ($environment === 'Help Desk' ? 'helpdesk' : 'studio') . '"'), "$label: ambiente marcado para a paleta");
    $siteLink = $host === 'studio.connect.lab' ? 'href="https://www.connect.lab/" target="_blank"' : 'href="https://www.connect.lab/" target="_blank"';
    $assert(str_contains($html, $siteLink), "$label: 'Visualizar site' aponta para o host do site");
}

// Host do Studio: helper reconhece só studio.*
$_SERVER['HTTP_HOST'] = 'studio.connect.lab';
$assert(studio_host() && !erp_host(), 'studio.connect.lab é host do Studio');
$_SERVER['HTTP_HOST'] = 'www.connect.lab';
$assert(!studio_host(), 'www.connect.lab não é host do Studio');
$_SERVER['HTTP_HOST'] = 'erp.connect.lab';
$assert(!studio_host(), 'erp.connect.lab não é host do Studio');

echo $failures ? "FALHAS:\n - " . implode("\n - ", $failures) . "\n" : '';
echo "$checks verificações, " . count($failures) . " falha(s)\n";
exit($failures ? 1 : 0);
