<?php $this->layout('layouts/theme'); ?>
<div class="hc-wrap hc-page">
    <nav class="hc-crumbs" aria-label="Você está em">
        <a href="<?= url('/suporte') ?>"><?= \Source\Services\Help\Icons::svg('home-outline') ?>Início</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <a href="<?= url('/suporte#assuntos') ?>">Assuntos</a><?= \Source\Services\Help\Icons::svg('chevron-forward-outline') ?>
        <span aria-current="page"><?= htmlspecialchars($category->title) ?></span>
    </nav>
    <div class="hc-layout">
        <div class="hc-main">
            <header class="hc-title">
                <i class="hc-title__icon"><?= \Source\Services\Help\Icons::svg($category->icon) ?></i>
                <h1><?= htmlspecialchars($category->title) ?></h1>
            </header>
            <?php if ($category->description): ?><p class="hc-lead"><?= htmlspecialchars((string)$category->description) ?></p><?php endif; ?>
            <?php
            // "Como funciona esta área" (ADR 0309): o resumo geral do assunto, dos arquivos da base (kb/areas).
            $areaKb = new \Source\Services\Help\KnowledgeBase();
            $areaText = $areaKb->areaIntro((string)$category->uri);
            if ($areaText !== null):
                $areaArticles = $areaKb->articles();
                $areaHtml = (new \Source\Services\Help\ArticleRenderer(
                    static fn (string $slug): ?string => isset($areaArticles[$slug]) ? url('/suporte/' . \Source\Services\Help\HelpSync::categoryUri($areaArticles[$slug]['category']) . '/' . $slug) : null
                ))->render($areaText)['html'];
            ?>
                <section class="hc-area" aria-labelledby="hc-area-title">
                    <h2 id="hc-area-title"><?= \Source\Services\Help\Icons::svg('reader-outline') ?>Como funciona esta área</h2>
                    <div class="hc-prose"><?= $areaHtml ?></div>
                </section>
            <?php endif; ?>
            <div class="hc-list">
                <?php if (!$articles): ?><div class="hc-empty">Ainda não há tutoriais publicados neste assunto.</div><?php endif; ?>
                <?php foreach ($articles as $item): $itemMeta = \Source\Controllers\Web\Support\Support::articleMeta($item); ?>
                    <a href="<?= url('/suporte/' . $category->uri . '/' . $item->uri) ?>">
                        <i><?= \Source\Services\Help\Icons::svg($itemMeta['icon']) ?></i>
                        <span><strong><?= htmlspecialchars($item->title) ?></strong><small><?= htmlspecialchars($item->summary) ?></small></span>
                        <?= \Source\Services\Help\Icons::svg('arrow-forward-outline') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <aside class="hc-side">
            <section class="hc-side__box">
                <h2><?= \Source\Services\Help\Icons::svg('albums-outline') ?>Outros assuntos</h2>
                <ul class="hc-dots"><?php foreach ($categories as $item): if ((int)$item->id === (int)$category->id) { continue; } ?><li><a href="<?= url('/suporte/' . $item->uri) ?>"><?= htmlspecialchars($item->title) ?></a></li><?php endforeach; ?></ul>
            </section>
            <?= $this->insert('pages/_help-box') ?>
        </aside>
    </div>
</div>
