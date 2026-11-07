<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Erro | Moves Studio</title>
    <?php $studioCss=dirname(__DIR__).'/assets/studio.min.css'; ?>
    <link rel="stylesheet" href="<?= themeStudio('/assets/studio.min.css', 'default') . '?v=' . filemtime($studioCss) ?>">
</head>
<body class="studio-error-body">
<a class="studio-skip-link" href="#main-content">Ir para o conteúdo</a>
<main id="main-content" tabindex="-1"><?= $this->section('content') ?></main>
</body>
</html>
