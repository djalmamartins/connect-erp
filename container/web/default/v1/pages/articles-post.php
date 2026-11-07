<?php
$this->layout("layouts/theme");
$articleUrl = url("/artigos/{$post->uri}");
$shareText = urlencode("{$post->title} - {$articleUrl}");
$authorName = ($author ? $author->fullName() : "Equipe Connect");
$authorPhoto = ($author && $author->photo() ? image($author->photo(), 120, 120) : theme("/assets/images/marca_connect.svg"));
?>

<section class="article-hearder" aria-hidden="true"></section>

<main class="article-page">
    <div class="article-container">
        <article class="article-content">
            <nav class="breadcrumb" aria-label="Navegação estrutural">
                <a href="<?= url("/artigos"); ?>">Artigos</a>
                <?php if ($category): ?>
                    <span aria-hidden="true">›</span>
                    <a href="<?= url("/artigos/em/{$category->uri}"); ?>"><?= $category->title; ?></a>
                <?php endif; ?>
                <span aria-hidden="true">›</span>
                <span aria-current="page"><?= $post->title; ?></span>
            </nav>

            <?php if ($category): ?>
                <a class="article-category" href="<?= url("/artigos/em/{$category->uri}"); ?>">
                    <?= mb_strtoupper($category->title); ?>
                </a>
            <?php endif; ?>

            <h1><?= $post->title; ?></h1>

            <?php if (!empty($post->subtitle)): ?>
                <p class="article-subtitle"><?= $post->subtitle; ?></p>
            <?php endif; ?>

            <div class="article-meta">
                <div class="meta-item">
                    <span class="icon-calendar"><?= date_fmt($post->post_at, "d/m/Y"); ?></span>
                </div>
                <div class="meta-divider"></div>
                <div class="meta-item">
                    <span class="icon-clock-o"><?= reading_time($post->content); ?> min de leitura</span>
                </div>
                <div class="meta-divider"></div>
                <div class="share">
                    <span class="icon-share-alt">Compartilhar</span>
                    <a href="https://wa.me/?text=<?= $shareText; ?>" target="_blank" rel="noopener noreferrer" aria-label="Compartilhar no WhatsApp">
                        <i class="icon-notext icon-whatsapp"></i>
                    </a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($articleUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="Compartilhar no Facebook">
                        <i class="icon-notext icon-facebook"></i>
                    </a>
                    <a href="https://www.linkedin.com/sharing/share-offsite/?url=<?= urlencode($articleUrl); ?>" target="_blank" rel="noopener noreferrer" aria-label="Compartilhar no LinkedIn">
                        <i class="icon-notext icon-linkedin"></i>
                    </a>
                </div>
            </div>

            <div class="article-cover">
                <?php if (!empty($post->video)): ?>
                    <div class="embed post_page_cover">
                        <iframe src="https://www.youtube.com/embed/<?= $post->video; ?>"
                                title="Vídeo: <?= $post->title; ?>"
                                allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
                                allowfullscreen></iframe>
                    </div>
                <?php elseif (!empty($post->cover)): ?>
                    <img class="post_page_cover" alt="<?= $post->title; ?>" title="<?= $post->title; ?>"
                         src="<?= image($post->cover, 1280, 720); ?>"/>
                <?php else: ?>
                    <img alt="<?= $post->title; ?>" title="<?= $post->title; ?>"
                         src="<?= theme("/assets/images/empty-content.svg"); ?>"/>
                <?php endif; ?>
            </div>

            <div class="article-text">
                <?= html_entity_decode($post->content); ?>
            </div>
        </article>

        <aside class="sidebar" aria-label="Informações complementares">
            <section class="sidebar-card author-card">
                <h2>Sobre o autor</h2>
                <div class="author">
                    <div class="author-logo">
                        <img src="<?= $authorPhoto; ?>" alt="<?= $authorName; ?>"/>
                    </div>
                    <div>
                        <strong><?= $authorName; ?></strong>
                        <p>Especialistas em gestão condominial, administração e soluções para condomínios.</p>
                    </div>
                </div>
                <a href="<?= url("/artigos"); ?>">Ver todos os artigos →</a>
            </section>

            <?php if (!empty($categories)): ?>
                <section class="sidebar-card">
                    <h2>Categorias</h2>
                    <ul class="categories">
                        <?php foreach ($categories as $item): ?>
                            <li class="<?= ($category && $category->id == $item->id ? "selected" : ""); ?>">
                                <a href="<?= url("/artigos/em/{$item->uri}"); ?>">
                                    <span><?= $item->title; ?></span>
                                </a>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

            <?php if (!empty($recent)): ?>
                <section class="sidebar-card">
                    <h2>Artigos recentes</h2>
                    <?php foreach ($recent as $recentPost): ?>
                        <article class="recent-post">
                            <a href="<?= url("/artigos/{$recentPost->uri}"); ?>" class="recent-post__image" aria-label="Ler <?= $recentPost->title; ?>">
                                <img src="<?= ($recentPost->cover ? image($recentPost->cover, 180, 130) : theme("/assets/images/empty-content.svg")); ?>"
                                     alt="<?= $recentPost->title; ?>"/>
                            </a>
                            <div>
                                <strong><a href="<?= url("/artigos/{$recentPost->uri}"); ?>"><?= $recentPost->title; ?></a></strong>
                                <time datetime="<?= date_fmt($recentPost->post_at, "Y-m-d"); ?>"><?= date_fmt($recentPost->post_at, "d/m/Y"); ?></time>
                            </div>
                        </article>
                    <?php endforeach; ?>
                    <a href="<?= url("/artigos"); ?>" class="all-posts">Ver todos os artigos →</a>
                </section>
            <?php endif; ?>

            <section class="sidebar-cta">
                <h2>Gestão profissional faz toda a diferença</h2>
                <p>Conte com a Connect para uma administração transparente, eficiente e próxima de você.</p>
                <a href="https://wa.me/<?= htmlspecialchars(preg_replace('/\D+/', '', CONF_SITE_WHATSAPP)) ?>" target="_blank" rel="noopener noreferrer" class="cta-whatsapp">
                    Falar com um especialista
                </a>
                <span class="cta-or">ou</span>
                <a href="<?= url("/solicite-sua-proposta"); ?>" class="cta-proposta">Solicitar proposta</a>
            </section>
        </aside>
    </div>

    <?php if (!empty($related)): ?>
        <section class="article-related" aria-labelledby="related-title">
            <div class="blog-container">
                <header class="posts-header">
                    <h2 id="related-title">Veja também <strong>nesta categoria</strong></h2>
                    <?php if ($category): ?>
                        <a href="<?= url("/artigos/em/{$category->uri}"); ?>" class="posts-header__link">Ver categoria →</a>
                    <?php endif; ?>
                </header>
                <div class="posts-grid">
                    <?php foreach ($related as $more): ?>
                        <?php $this->insert("pages/articles-list-blog", ["post" => $more]); ?>
                    <?php endforeach; ?>
                </div>
            </div>
        </section>
    <?php endif; ?>
</main>
