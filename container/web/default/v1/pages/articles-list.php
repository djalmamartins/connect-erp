<article>
    <div><?php if ($post->cover) { ?>
            <img title="<?= $post->title; ?>" alt="<?= $post->title; ?>"
                 src="<?= image($post->cover, 600, 340); ?>"/>
        <?php } else { ?>
            <img title="<?= $post->title; ?>" alt="<?= $post->title; ?>"
                 src="<?= theme("/assets/images/empty-content.svg"); ?>"/>
        <?php } ?></div>
    <small><a title="Artigos em <?= $post->category()->title; ?>"
              href="<?= url("/artigos/em/{$post->category()->uri}"); ?>"><?= $post->category()->title; ?></a>
    </small>
    <h3><a title="<?= $post->title; ?>"
           href="<?= url("/artigos/{$post->uri}"); ?>"><b><?= $post->title; ?></b></a></h3>
    <a title="<?= $post->title; ?>" href="<?= url("/artigos/{$post->uri}"); ?>">Ler artigo →</a>
</article>
