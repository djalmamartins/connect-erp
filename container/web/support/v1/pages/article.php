<?php $this->layout('layouts/theme'); ?>
<div class="hc-wrap hc-page">
    <nav class="hc-crumbs" aria-label="Você está em">
        <a href="<?= url('/suporte') ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>Início</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <a href="<?= url('/suporte#assuntos') ?>">Assuntos</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <a href="<?= url('/suporte/' . $category->uri) ?>"><?= htmlspecialchars($category->title) ?></a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <span aria-current="page">Artigo</span>
    </nav>
    <div class="hc-layout">
        <article class="hc-main hc-article">
            <header class="hc-title">
                <i class="hc-title__icon"><?= \Source\Services\Help\Icons::svg($meta['icon']) ?></i>
                <h1><?= htmlspecialchars($article->title) ?></h1>
            </header>
            <?php if ($cover): ?>
                <div class="hc-cover">
                    <a class="hc-cover__img" href="<?= htmlspecialchars($cover) ?>" target="_blank" rel="noopener"><img src="<?= htmlspecialchars($cover) ?>" alt="Tela: <?= htmlspecialchars($article->title) ?>" width="1440" height="900"></a>
                    <p class="hc-cover__note"><?= \Source\Services\Help\Icons::svg('image-outline') ?><span>Print real do sistema. Os números nas imagens mostram onde clicar; a legenda explica cada um.</span></p>
                </div>
            <?php endif; ?>
            <p class="hc-summary"><?= htmlspecialchars($article->summary) ?></p>
            <?php if ($meta['menu'] !== ''): ?>
                <p class="hc-where"><?= \Source\Services\Help\Icons::svg('navigate-outline') ?>No sistema: <?= (new \Source\Services\Help\ArticleRenderer(static fn () => null))->inline('[[' . $meta['menu'] . ']]') ?></p>
            <?php endif; ?>
            <div class="hc-prose"><?= $article->content ?></div>
            <section class="hc-vote" aria-labelledby="hc-vote-title">
                <small>Feedback</small>
                <h2 id="hc-vote-title">Gostou ou foi útil este conteúdo?</h2>
                <form action="<?= url('/suporte/avaliar/' . $article->id) ?>" method="post"><?= csrf_input() ?>
                    <button name="vote" value="yes"><?= \Source\Services\Help\Icons::svg('thumbs-up-outline') ?>Sim</button>
                    <button name="vote" value="no"><?= \Source\Services\Help\Icons::svg('thumbs-down-outline') ?>Não</button>
                </form>
                <p aria-live="polite"></p>
            </section>
        </article>
        <aside class="hc-side">
            <?php if (count($meta['toc']) > 1): ?>
                <section class="hc-side__box hc-toc">
                    <h2><?= \Source\Services\Help\Icons::svg('list-outline') ?>Navegue pelo conteúdo</h2>
                    <ul class="hc-dots"><?php foreach ($meta['toc'] as $item): ?><li><a href="#<?= htmlspecialchars($item['id']) ?>"><?= htmlspecialchars(preg_replace('/\[\[|\]\]|\{\{|\}\}|\*\*/', '', $item['title'])) ?></a></li><?php endforeach; ?></ul>
                </section>
            <?php endif; ?>
            <?php if ($related): ?>
                <section class="hc-side__box">
                    <h2><?= \Source\Services\Help\Icons::svg('reader-outline') ?>Artigos relacionados</h2>
                    <ul class="hc-dots"><?php foreach ($related as $item): ?><li><a href="<?= url($item['url']) ?>"><?= htmlspecialchars($item['title']) ?></a></li><?php endforeach; ?></ul>
                    <a class="hc-more" href="<?= url('/suporte/' . $category->uri) ?>">Ver todos <?= \Source\Services\Help\Icons::svg('arrow-forward-outline') ?></a>
                </section>
            <?php endif; ?>
            <?= $this->insert('pages/_help-box') ?>
        </aside>
    </div>
</div>
