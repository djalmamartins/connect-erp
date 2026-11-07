<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/events/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-calendar-o">Eventos</h2>
        <form action="<?= url("/studio/events/home"); ?>" method="post" class="app_search_form">
            <input type="text" name="s" value="<?= $search; ?>" placeholder="Pesquisar Eventos:">
            <button class="icon-search icon-notext"></button>
        </form>
    </header>

    <div class="dash_content_app_box">
        <section>
            <div class="app_blog_home">
                <?php if (!$posts): ?>
                    <div class="message info icon-info">Ainda não existem eventos cadastrados.</div>
                <?php else: ?>
                    <?php foreach ($posts as $post):
                        $postCover = ($post->cover ? image($post->cover, 300) : "");
                        ?>
                        <article>
                            <div style="background-image: url(<?= $postCover; ?>);"
                                 class="cover embed radius">
                            </div>
                            <header>
                                <div class="actions">
                                    <a class="icon-pencil btn btn-blue" title=""
                                       href="<?= url("/studio/events/post/{$post->id}"); ?>">Editar</a>
                                    <a class="icon-trash btn btn-red" title="" href="#"
                                       data-post="<?= url("/studio/events/post"); ?>"
                                       data-action="delete"
                                       data-confirm="Tem certeza que deseja deletar esse post?"
                                       data-post_id="<?= $post->id; ?>">Deletar</a>
                                </div>

                                <h3 class="tittle">
                                    <a target="_blank" href=" <?= url("/eventos/{$post->uri}"); ?>">
                                        <?php if ($post->status == 'draft' ): ?>
                                            <span class="info icon-pencil-square-o"><?= $post->title; ?></span>
                                        <?php elseif($post->status == 'trash'): ?>
                                            <span class="red icon-trash"><?= $post->title; ?></span>
                                        <?php elseif($post->status == 'post' && $post->post_at > date("Y-m-d H:i:s")): ?>
                                            <span class="yellow icon-clock-o"><?= $post->title; ?></span>
                                        <?php else: ?>
                                            <span class="green icon-check"><?= $post->title; ?></span>
                                        <?php endif; ?>
                                    </a>
                                </h3>

                                <div class="footer">
                                    <p class="icon-clock-o"><?= date_fmt($post->post_at, "d.m.y \à\s H\hi"); ?></p>
                                    <p class="icon-bookmark"><?= $post->category()->title; ?></p>
                                </div>
                                <div class="footer">
                                    <p class="icon-user"><?= $post->author()->first_name; ?></p>
                                    <p class="icon-bar-chart"><?= $post->views; ?></p>

                                </div>
                            </header>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?= $paginator; ?>
        </section>
    </div>
</section>