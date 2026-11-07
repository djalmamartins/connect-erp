<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head; ?>
    <link rel="icon" type="image/png" href="<?= theme("/assets/images/favicon.png"); ?>"/>
    <link rel="stylesheet" href="<?= url('/container/shared/assets/organic/organic.min.css') . '?v=' . filemtime(dirname(__DIR__, 5) . '/container/shared/assets/organic/organic.min.css') ?>"/>
    <link rel="stylesheet" href="<?= url('/container/shared/assets/organic/compat-v1.css') . '?v=' . filemtime(dirname(__DIR__, 5) . '/container/shared/assets/organic/compat-v1.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/site.min.css') . '?v=' . filemtime(__DIR__ . '/../assets/site.min.css') ?>"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
</head>
<body class="organic-error-layout">
<a class="org-skip-link" href="#main-content">Ir para o conteúdo</a>
<!--LOAD-->
<?= $this->insert("pages/load", $this->data); ?>

<!--HEADER-->


<!--MAIN-->
<main id="main-content" tabindex="-1">
    <?= $this->section("content"); ?>
</main>

<!--OPTOUT-->
<?php if ($this->section("optout")): ?>
    <?= $this->section("optout"); ?>
<?php else: ?>
    <?= $this->insert("pages/footer_optout", $this->data); ?>
<?php endif; ?>

<!--FOOTER-->
<?= $this->insert("pages/footer", $this->data); ?>

<!--MODALS-->
<?= $this->insert("pages/modals", $this->data); ?>
<script src="<?= url('/container/shared/assets/organic/organic.global.min.js') . '?v=' . filemtime(dirname(__DIR__, 5) . '/container/shared/assets/organic/organic.global.min.js') ?>"></script>
<script src="<?= url('/container/shared/assets/organic/compat-v1.js') . '?v=' . filemtime(dirname(__DIR__, 5) . '/container/shared/assets/organic/compat-v1.js') ?>"></script>
<script src="<?= theme('/assets/site.min.js') . '?v=' . filemtime(__DIR__ . '/../assets/site.min.js'); ?>"></script>
<?= $this->section("scripts"); ?>
</body>
</html>
