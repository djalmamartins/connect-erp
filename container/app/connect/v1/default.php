<?php
/**
 * Moldura do aplicativo do morador (ADR 0302): celular primeiro, instalável (manifest + service worker).
 * Topo com o logotipo da Connect e a unidade; barra inferior com as funções principais e "Mais".
 * No computador o menu vira uma coluna lateral. Dados: AppScreens::render().
 *
 * @var string $head @var string $title @var \Source\Models\User $user
 * @var list<array> $links vínculos válidos @var ?array $link vínculo atual
 * @var list<array{key:string,label:string,path:string,icon:string,active:bool,tab:bool}> $menu
 */
$asset = static function (string $path): string {
    $file = __DIR__ . $path;
    return themeApp($path) . '?v=' . (is_file($file) ? filemtime($file) : 0);
};
$tabs = array_values(array_filter($menu, static fn (array $item): bool => $item['tab']));
$more = array_values(array_filter($menu, static fn (array $item): bool => !$item['tab']));
$moreActive = (bool)array_filter($more, static fn (array $item): bool => $item['active']);
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#0d2e27">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="Connect">
    <?= $head ?? '' ?>
    <link rel="manifest" href="<?= app_url('/manifest.webmanifest') ?>">
    <link rel="icon" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="apple-touch-icon" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/app.css') ?>">
</head>
<body class="app-body" data-sw="<?= $this->e(app_url('/sw.js')) ?>" data-scope="<?= $this->e(app_url('/')) ?>">
<a class="app-skip" href="#app-main">Ir para o conteúdo</a>
<?php $this->insert('partials/icons'); ?>
<header class="app-top">
    <a class="app-top__brand" href="<?= app_url('/') ?>" aria-label="Início">
        <img src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios" width="128" height="34">
    </a>
    <?php if ($link !== null): ?>
        <?php if (count($links) > 1): ?>
            <form class="app-unit" method="post" action="<?= app_url('/unidade') ?>">
                <?= csrf_input() ?>
                <label class="app-sr" for="app-unit">Unidade</label>
                <select id="app-unit" name="resident_id" data-app-autosubmit>
                    <?php foreach ($links as $item): ?>
                        <option value="<?= (int)$item['resident_id'] ?>"<?= $item['resident_id'] === $link['resident_id'] ? ' selected' : '' ?>><?= $this->e($item['unit_label'] . ' · ' . $item['condominium_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <noscript><button type="submit">Trocar</button></noscript>
            </form>
        <?php else: ?>
            <p class="app-unit app-unit--single"><strong><?= $this->e($link['unit_label']) ?></strong><span><?= $this->e($link['condominium_name']) ?></span></p>
        <?php endif; ?>
    <?php endif; ?>
    <form method="post" action="<?= app_url('/sair') ?>" class="app-top__out">
        <?= csrf_input() ?>
        <button type="submit" class="app-icon-button" aria-label="Sair"><svg aria-hidden="true"><use href="#i-logout"></use></svg></button>
    </form>
</header>

<div class="app-shell">
    <?php if ($menu !== []): ?>
    <nav class="app-side" aria-label="Menu">
        <?php foreach ($menu as $item): ?>
            <a href="<?= app_url($item['path']) ?>" class="app-side__item<?= $item['active'] ? ' is-active' : '' ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
                <svg aria-hidden="true"><use href="#i-<?= $this->e($item['icon']) ?>"></use></svg><?= $this->e($item['label']) ?>
            </a>
        <?php endforeach; ?>
    </nav>
    <?php endif; ?>
    <main id="app-main" class="app-main" tabindex="-1">
        <?php if ($appFlash = flash()): ?><div class="app-flash" role="status"><?= $appFlash ?></div><?php endif; ?>
        <?= $this->section('content') ?>
    </main>
</div>

<?php if ($menu !== []): ?>
<nav class="app-tabs" aria-label="Atalhos">
    <?php foreach ($tabs as $item): ?>
        <a href="<?= app_url($item['path']) ?>" class="app-tabs__item<?= $item['active'] ? ' is-active' : '' ?>"<?= $item['active'] ? ' aria-current="page"' : '' ?>>
            <svg aria-hidden="true"><use href="#i-<?= $this->e($item['icon']) ?>"></use></svg><span><?= $this->e($item['label']) ?></span>
        </a>
    <?php endforeach; ?>
    <?php if ($more !== []): ?>
    <details class="app-tabs__more<?= $moreActive ? ' is-active' : '' ?>">
        <summary class="app-tabs__item"><svg aria-hidden="true"><use href="#i-grid"></use></svg><span>Mais</span></summary>
        <div class="app-more" role="menu">
            <?php foreach ($more as $item): ?>
                <a role="menuitem" href="<?= app_url($item['path']) ?>" class="app-more__item<?= $item['active'] ? ' is-active' : '' ?>">
                    <svg aria-hidden="true"><use href="#i-<?= $this->e($item['icon']) ?>"></use></svg><?= $this->e($item['label']) ?>
                </a>
            <?php endforeach; ?>
        </div>
    </details>
    <?php endif; ?>
</nav>
<?php endif; ?>
<script src="<?= $asset('/assets/js/app.js') ?>" defer></script>
</body>
</html>
