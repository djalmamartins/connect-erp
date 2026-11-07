<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?= $head ?>
    <?php $studioCss=dirname(__DIR__).'/assets/studio.min.css'; ?>
    <link rel="stylesheet" href="<?= themeStudio('/assets/studio.min.css', 'default') . '?v=' . filemtime($studioCss) ?>">
    <link rel="icon" href="<?= theme('/assets/images/favicon.png') ?>">
</head>
<body class="studio-login-body" data-environment="<?= htmlspecialchars($loginEnvironment ?? 'studio') ?>">
<a class="studio-skip-link" href="#main-content">Ir para o conteúdo</a>
<div class="ajax_load"><div class="ajax_load_box"><div class="ajax_load_box_circle"></div><p class="ajax_load_box_title">Entrando...</p></div></div>
<main id="main-content" tabindex="-1"><?= $this->section('content') ?></main>
<script src="<?= url('/container/shared/assets/vendor/scripts/jquery.min.js') ?>"></script>
<script src="<?= url('/container/shared/assets/vendor/scripts/jquery-ui.js') ?>"></script>
<script src="<?= themeStudio('/assets/js/login.js', 'default') ?>"></script>
<?= $this->section('scripts') ?>
</body>
</html>
