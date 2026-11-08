<?php
/**
 * Moldura única do Connect ERP v1 (sem layouts/ paralelos).
 * As páginas chamam $this->layout('default') e preenchem a seção 'content'.
 * Dados vêm de Erp\Connect\Panel::layoutData(): $head, $title, $user, $catalog,
 * $currentPageId, $currentGroup, $breadcrumbs, $demo, $notifications, $icon, $anchor.
 */
// Usa o bundle do MovesCode Compress (Services/Minify/Erp.php) quando está atualizado;
// senão, o arquivo-fonte do design system.
$erpAsset = static function (string $source, string $bundle): string {
    $path = is_file(__DIR__ . $bundle) && is_file(__DIR__ . $source) && filemtime(__DIR__ . $bundle) >= filemtime(__DIR__ . $source) ? $bundle : $source;
    return themeErp($path) . '?v=' . (is_file(__DIR__ . $path) ? filemtime(__DIR__ . $path) : 0);
};
$searchIndex = [];
foreach ($catalog['pages'] as $id => $page) {
    if (!$canSee($id)) {
        continue;
    }
    $searchIndex[] = ['n' => $page['name'], 'g' => $catalog['groups'][$page['group']]['label'] . ($page['section'] ? ' · ' . $page['section'] : ''), 'u' => erp_url($page['route'])];
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <?= $head ?? '' ?>
    <link rel="icon" href="<?= theme('/assets/images/favicon-32x32.png') ?>">
    <link rel="stylesheet" href="<?= $erpAsset('/assets/css/erp.css', '/assets/erp.min.css') ?>">
</head>
<body class="erp-body">
<a class="erp-skip-link" href="#erp-main">Ir para o conteúdo</a>
<?php if ($withNavigation ?? true): ?>
<div class="erp-shell" data-erp-shell>
    <?php $this->insert('components/sidebar/sidebar'); ?>
    <div class="erp-content">
        <?php $this->insert('components/navbar/navbar'); ?>
        <main id="erp-main" class="erp-main" tabindex="-1">
            <?php if ($erpFlash = flash()): ?><div class="erp-flash" role="status"><?= $erpFlash ?></div><?php endif; ?>
            <?= $this->section('content') ?>
        </main>
    </div>
</div>
<?php else: ?>
<main id="erp-main" class="erp-main" tabindex="-1"><?= $this->section('content') ?></main>
<?php endif; ?>
<div class="erp-toasts" data-erp-toasts role="status" aria-live="polite"></div>
<script type="application/json" id="erp-search-index"><?= json_encode($searchIndex, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="<?= $erpAsset('/assets/js/erp.js', '/assets/erp.min.js') ?>" defer></script>
<?= $this->section('scripts') ?>
</body>
</html>
