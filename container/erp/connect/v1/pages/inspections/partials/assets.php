<?php
/**
 * CSS/JS da vistoria e mensagem da última ação. Incluído no topo de cada página do módulo.
 * @var ?array{type: string, text: string} $flash
 */
$asset = static function (string $path): string {
    $file = dirname(__DIR__, 3) . $path;
    return themeErp($path) . '?v=' . (is_file($file) ? filemtime($file) : 0);
};
?>
<link rel="stylesheet" href="<?= $asset('/assets/css/inspections.css') ?>">
<?php if (!empty($flash)): ?>
    <div class="erp-alert erp-alert--<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> insp-flash" role="<?= $flash['type'] === 'success' ? 'status' : 'alert' ?>"><?= $this->e($flash['text']) ?></div>
<?php endif; ?>
<script src="<?= $asset('/assets/js/inspections.js') ?>" defer></script>
