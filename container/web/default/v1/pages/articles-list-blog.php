<!-- ARTIGO -->
<article class="post-card">
    <a href="<?= url("/artigos/{$post->uri}"); ?>" class="post-card__image" aria-label="Ler <?= $post->title; ?>">
        <?php if ($post->cover) { ?>
            <img title="<?= $post->title; ?>" alt="<?= $post->title; ?>"
                 src="<?= image($post->cover, 600, 340); ?>"/>
        <?php } else { ?>
            <img title="<?= $post->title; ?>" alt="<?= $post->title; ?>"
                 src="<?= theme("/assets/images/empty-content.svg"); ?>"/>
        <?php } ?>
    </a>
    <div class="post-card__content">
        <div class="post-card__meta">
            <span class="post-card__category"><a title="Artigos em <?= $post->category()->title; ?>" href="<?= url("/artigos/em/{$post->category()->uri}"); ?>"><?= $post->category()->title; ?></a></span>
            <time><?= date_fmt($post->post_at); ?></time>
        </div>
        <h3><a title="<?= $post->title; ?>" href="<?= url("/artigos/{$post->uri}"); ?>"><b><?= $post->title; ?></b></a></h3>
        <p><?= str_limit_chars(strip_tags($post->content), 120); ?></p>
        <div class="read-more">
            <a title="<?= $post->title; ?>" href="<?= url("/artigos/{$post->uri}"); ?>">Ler artigo →</a>
        </div>
    </div>
</article>
