<?php $this->layout('layouts/theme'); ?>
<section class="legal-hero"><div class="container"><span><?= htmlspecialchars(mb_strtoupper(CONF_SITE_NAME)) ?></span><h1><?= htmlspecialchars($title) ?></h1><p>Versão de <?= htmlspecialchars($versionLabel) ?>.</p></div></section>
<section class="legal-page"><article class="container legal-page__content"><?= $this->insert('pages/legal/' . $template) ?></article></section>
