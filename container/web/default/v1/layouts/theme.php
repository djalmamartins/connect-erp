<!DOCTYPE html>
<html lang="pt-br">
<head>

    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <?= $head; ?>
    <link rel="icon" href="<?= site_favicon_url(); ?>"/>
    <link rel="stylesheet" href="<?= url('/organic/organic.min.css') . '?v=' . filemtime(dirname(__DIR__, 4) . '/organic/organic.min.css') ?>"/>
    <link rel="stylesheet" href="<?= url('/organic/compat-v1.css') . '?v=' . filemtime(dirname(__DIR__, 4) . '/organic/compat-v1.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/style.css') . '?v=' . filemtime(__DIR__ . '/../assets/style.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/messages.css') . '?v=' . filemtime(__DIR__ . '/../assets/messages.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/responsive.css') . '?v=' . filemtime(__DIR__ . '/../assets/responsive.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/pages.css') . '?v=' . filemtime(__DIR__ . '/../assets/pages.css') ?>"/>
    <link rel="stylesheet" href="<?= theme('/assets/motion.css') . '?v=' . filemtime(__DIR__ . '/../assets/motion.css') ?>"/>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,100..700,0..1,-50..200" />
</head>
<body class="organic-site-layout">
<a class="org-skip-link" href="#main-content">Ir para o conteúdo</a>
<!--LOAD-->
<?= $this->insert("pages/load", $this->data); ?>

<!--HEADER-->
<?= $this->insert("pages/header", $this->data); ?>

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
<script async src="https://www.googletagmanager.com/gtag/js?id=UA-53658515-18"></script>
<?= $this->insert("pages/modals", $this->data); ?>
<script src="<?= url('/organic/organic.global.min.js') . '?v=' . filemtime(dirname(__DIR__, 4) . '/organic/organic.global.min.js') ?>"></script>
<script src="<?= url('/organic/compat-v1.js') . '?v=' . filemtime(dirname(__DIR__, 4) . '/organic/compat-v1.js') ?>"></script>
<script src="<?= theme("/assets/scripts.js"); ?>"></script>
<script src="<?= theme("/assets/js/navigation.js") . '?v=' . filemtime(__DIR__ . '/../assets/js/navigation.js'); ?>"></script>
<script src="<?= theme("/assets/js/counters.js") . '?v=' . filemtime(__DIR__ . '/../assets/js/counters.js'); ?>"></script>
<script src="<?= theme("/assets/js/testimonials.js"); ?>"></script>
<script src="<?= theme("/assets/js/motion.js"); ?>"></script>
<script src="<?= url('/container/shared/assets/js/helpdesk-widget.js') ?>" data-moves-helpdesk-widget defer></script>
<?= $this->section("scripts"); ?>
</body>
</html>
