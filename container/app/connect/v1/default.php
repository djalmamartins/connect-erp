<?php
/**
 * Moldura do aplicativo do morador (ADR 0302), no visual do Realizze Pessoa Física: canvas claro, menu lateral
 * branco no computador (logo, unidade, menu e conta no rodapé) e, no celular, topo branco com a unidade e barra
 * inferior com Início, Resolva fácil, Unidade e Perfil (revisão de 15/11). Instalável (manifest + service worker).
 * Dados: AppScreens::render().
 *
 * @var string $head @var string $title @var \Source\Models\User $user
 * @var list<array> $links vínculos válidos @var ?array $link vínculo atual
 * @var list<array{key:string,label:string,path:string,icon:string,active:bool,tab:bool,tab_active:bool}> $menu
 * @var int $unseen avisos novos no sininho @var ?string $photo endereço da foto da conta (revisão de 15/11)
 */
$asset = static function (string $path): string {
    $file = __DIR__ . $path;
    return themeApp($path) . '?v=' . (is_file($file) ? filemtime($file) : 0);
};
$tabs = array_values(array_filter($menu, static fn (array $item): bool => $item['tab']));
$personName = trim((string)($link['name'] ?? ''));
$nameParts = $personName !== '' ? (preg_split('/\s+/u', $personName) ?: []) : [];
$shortName = $nameParts === [] ? 'Morador' : $nameParts[0] . (count($nameParts) > 1 ? ' ' . end($nameParts) : '');
$unseen = (int)($unseen ?? 0);
$photo = $photo ?? null;
$bell = function (string $class = '') use ($unseen): string {
    return '<a class="app-icon-button app-bell ' . $class . '" href="' . app_url('/avisos') . '" aria-label="Avisos' . ($unseen > 0 ? ' (' . $unseen . ' novos)' : '') . '" title="Avisos">'
        . '<svg aria-hidden="true"><use href="#i-bell"></use></svg>' . ($unseen > 0 ? '<span class="app-bell__count">' . ($unseen > 9 ? '9+' : $unseen) . '</span>' : '') . '</a>';
};
$initials = static function (string $text): string {
    $parts = preg_split('/[\s\-·]+/u', trim($text)) ?: [];
    $out = '';
    foreach ($parts as $part) {
        if ($part !== '' && mb_strlen($out) < 2) {
            $out .= mb_strtoupper(mb_substr($part, 0, 1));
        }
    }
    return $out !== '' ? $out : '•';
};
/** Unidade: seletor quando há mais de uma, texto quando é uma só. $id distingue o topo e o menu lateral. */
$unit = function (string $id) use ($link, $links): string {
    if ($link === null) {
        return '';
    }
    if (count($links) > 1) {
        $html = '<form class="app-unit" method="post" action="' . app_url('/unidade') . '">' . csrf_input()
            . '<label class="app-sr" for="' . $id . '">Unidade</label><select id="' . $id . '" name="resident_id" data-app-autosubmit>';
        foreach ($links as $item) {
            $html .= '<option value="' . (int)$item['resident_id'] . '"' . ($item['resident_id'] === $link['resident_id'] ? ' selected' : '') . '>'
                . $this->e($item['unit_label'] . ' · ' . $item['condominium_name']) . '</option>';
        }
        return $html . '</select><noscript><button type="submit">Trocar</button></noscript></form>';
    }
    return '<p class="app-unit app-unit--single"><strong>' . $this->e($link['unit_label']) . '</strong><span>' . $this->e($link['condominium_name']) . '</span></p>';
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f8faf9">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Connect">
    <?= $head ?? '' ?>
    <link rel="manifest" href="<?= app_url('/manifest.webmanifest') ?>">
    <link rel="icon" type="image/svg+xml" href="<?= themeApp('/assets/images/favicon.svg') ?>">
    <link rel="icon" type="image/png" sizes="192x192" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="apple-touch-icon" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/app.css') ?>">
    <?= connect_fields_assets('css') /* upload e datas padrão (ADR 0624) */ ?>
</head>
<body class="app-body" data-sw="<?= $this->e(app_url('/sw.js')) ?>" data-scope="<?= $this->e(app_url('/')) ?>">
<a class="app-skip" href="#app-main">Ir para o conteúdo</a>
<?php $this->insert('partials/icons'); ?>
<header class="app-top">
    <?php if ($link !== null): ?>
        <div class="app-top__who">
            <strong><?= $this->e($shortName) ?></strong>
            <?= $unit('app-unit') ?>
        </div>
        <a class="app-icon-button" href="<?= app_url('/buscar') ?>" aria-label="Buscar" title="Buscar"><svg aria-hidden="true"><use href="#i-search"></use></svg></a>
        <?= $bell() ?>
        <a class="app-top__avatar" href="<?= app_url('/perfil') ?>" aria-label="Perfil" title="Perfil"><?php if ($photo !== null): ?><img src="<?= $this->e($photo) ?>" alt="" width="38" height="38"><?php else: ?><?= $this->e($initials($personName)) ?><?php endif; ?></a>
    <?php else: ?>
        <a class="app-top__brand" href="<?= app_url('/') ?>" aria-label="Início">
            <img src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios" width="128" height="34">
        </a>
        <form method="post" action="<?= app_url('/sair') ?>" class="app-top__out">
            <?= csrf_input() ?>
            <button type="submit" class="app-icon-button" aria-label="Sair" title="Sair"><svg aria-hidden="true"><use href="#i-logout"></use></svg></button>
        </form>
    <?php endif; ?>
</header>

<div class="app-shell">
    <?php if ($menu !== []): ?>
    <aside class="app-side">
        <a class="app-side__brand" href="<?= app_url('/') ?>" aria-label="Início">
            <img src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios" width="152" height="40">
        </a>
        <?php if ($link !== null): ?>
            <div class="app-context">
                <span class="app-context__avatar" aria-hidden="true"><?= $this->e($initials((string)$link['unit_label'])) ?></span>
                <?= $unit('app-unit-side') ?>
            </div>
        <?php endif; ?>
        <nav class="app-side__nav" aria-label="Menu">
            <?php foreach ($menu as $item): ?>
                <a href="<?= app_url($item['path']) ?>" class="app-side__item<?= $item['active'] ? ' is-active' : '' ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                    <svg aria-hidden="true"><use href="#i-<?= $this->e($item['icon']) ?>"></use></svg><?= $this->e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="app-profile">
            <span class="app-profile__avatar" aria-hidden="true"><?= $this->e($initials($personName)) ?></span>
            <span class="app-profile__name"><strong><?= $this->e($personName !== '' ? $personName : 'Morador') ?></strong><small><?= $this->e((string)($link['relationship_label'] ?? '')) ?></small></span>
            <form method="post" action="<?= app_url('/sair') ?>">
                <?= csrf_input() ?>
                <button type="submit" class="app-icon-button" aria-label="Sair" title="Sair"><svg aria-hidden="true"><use href="#i-logout"></use></svg></button>
            </form>
        </div>
    </aside>
    <?php endif; ?>
    <main id="app-main" class="app-main" tabindex="-1">
        <?php if ($link !== null): ?>
        <div class="app-bar">
            <div class="app-bar__hello"><strong><?= $this->e($shortName) ?></strong><span><?= $this->e($link['unit_label'] . ' · ' . $link['condominium_name']) ?></span></div>
            <form class="app-bar__search" method="get" action="<?= app_url('/buscar') ?>" role="search">
                <svg aria-hidden="true"><use href="#i-search"></use></svg>
                <label class="app-sr" for="app-bar-q">Buscar</label>
                <input id="app-bar-q" type="search" name="q" placeholder="Buscar comunicados, documentos, encomendas…" maxlength="80" value="<?= $this->e(is_string($_GET['q'] ?? null) ? (string)$_GET['q'] : '') ?>">
            </form>
            <?= $bell() ?>
            <a class="app-bar__avatar" href="<?= app_url('/perfil') ?>" title="Perfil" aria-label="Perfil"><?php if ($photo !== null): ?><img src="<?= $this->e($photo) ?>" alt="" width="38" height="38"><?php else: ?><?= $this->e($initials($personName)) ?><?php endif; ?></a>
        </div>
        <?php endif; ?>
        <?php if ($appFlash = flash()): ?><div class="app-flash" role="status"><?= $appFlash ?></div><?php endif; ?>
        <?= $this->section('content') ?>
    </main>
</div>

<?php if ($menu !== []): ?>
<nav class="app-tabs" aria-label="Menu principal">
    <?php foreach ($tabs as $item): ?>
        <a href="<?= app_url($item['path']) ?>" class="app-tabs__item<?= $item['tab_active'] ? ' is-active' : '' ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
            <svg aria-hidden="true"><use href="#i-<?= $this->e($item['icon']) ?>"></use></svg><span><?= $this->e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
<script src="<?= $asset('/assets/js/app.js') ?>" defer></script>
<?= connect_fields_assets('js') ?>
</body>
</html>
