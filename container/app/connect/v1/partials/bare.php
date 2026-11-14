<?php
/** Moldura simples (login, sem conexão e erro) do app do morador (ADR 0302). @var string $head */
$asset = static function (string $path): string {
    $file = dirname(__DIR__) . $path;
    return themeApp($path) . '?v=' . (is_file($file) ? filemtime($file) : 0);
};
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="theme-color" content="#f8faf9">
    <?= $head ?? '' ?>
    <link rel="manifest" href="<?= app_url('/manifest.webmanifest') ?>">
    <link rel="icon" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="apple-touch-icon" href="<?= themeApp('/assets/images/icon-192.png') ?>">
    <link rel="stylesheet" href="<?= $asset('/assets/css/app.css') ?>">
</head>
<body class="app-body app-body--bare" data-sw="<?= $this->e(app_url('/sw.js')) ?>" data-scope="<?= $this->e(app_url('/')) ?>">
<?= $this->section('content') ?>
<script src="<?= $asset('/assets/js/app.js') ?>" defer></script>
</body>
</html>
