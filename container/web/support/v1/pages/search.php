<?php $this->layout('layouts/theme'); ?>
<div class="hc-wrap hc-page">
    <nav class="hc-crumbs" aria-label="Você está em">
        <a href="<?= url('/suporte') ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>Início</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <span aria-current="page">Busca</span>
    </nav>
    <div class="hc-layout">
        <div class="hc-main">
            <header class="hc-title"><i class="hc-title__icon"><?= \Source\Services\Help\Icons::svg('search-outline') ?></i><h1><?= $search !== '' ? 'Resultados para “' . htmlspecialchars($search) . '”' : 'Pesquise na Central de Ajuda' ?></h1></header>
            <form class="hc-search hc-search--inline" action="<?= url('/suporte/buscar') ?>" method="get" role="search">
                <?= \Source\Services\Help\Icons::svg('search-outline') ?>
                <input name="q" type="search" value="<?= htmlspecialchars($search) ?>" placeholder="Pesquise uma dúvida" minlength="2" required aria-label="Pesquisar na Central de Ajuda">
                <button class="hc-btn hc-btn--dark" type="submit">Buscar</button>
            </form>
            <p class="hc-muted"><?= count($articles) ?> <?= count($articles) === 1 ? 'tutorial encontrado' : 'tutoriais encontrados' ?>.</p>
            <div class="hc-list">
                <?php if ($search !== '' && !$articles): ?><div class="hc-empty">Nada encontrado. Tente palavras mais simples, como “boleto” ou “unidade”, ou <a href="<?= url('/suporte#mensagem') ?>">fale com a gente</a>.</div><?php endif; ?>
                <?php foreach ($articles as $item): $itemCategory = $item->category(); if (!$itemCategory) { continue; } $itemMeta = \Source\Controllers\Web\Support\Support::articleMeta($item); ?>
                    <a href="<?= url('/suporte/' . $itemCategory->uri . '/' . $item->uri) ?>">
                        <i><?= \Source\Services\Help\Icons::svg($itemMeta['icon']) ?></i>
                        <span><strong><?= htmlspecialchars($item->title) ?></strong><small><?= htmlspecialchars($itemCategory->title) ?> · <?= htmlspecialchars($item->summary) ?></small></span>
                        <?= \Source\Services\Help\Icons::svg('arrow-forward-outline') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <aside class="hc-side">
            <section class="hc-side__box">
                <h2><?= \Source\Services\Help\Icons::svg('albums-outline') ?>Assuntos</h2>
                <ul class="hc-dots"><?php foreach ($categories as $item): ?><li><a href="<?= url('/suporte/' . $item->uri) ?>"><?= htmlspecialchars($item->title) ?></a></li><?php endforeach; ?></ul>
            </section>
            <?= $this->insert('pages/_help-box') ?>
        </aside>
    </div>
</div>
