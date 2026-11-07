<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/events/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-pencil-square-o">Categorias</h2>
        <a class="icon-plus-circle btn btn-green" href="<?= url("/studio/events/category"); ?>">Nova Categoria</a>
    </header>

    <div class="dash_content_app_box">
        <section>
            <div class="app_blog_categories">
                <?php if (!$categories): ?>
                    <div class="message info icon-info">Ainda não existem categorias cadastradas para seus eventos</div>
                <?php else: ?>
                    <?php foreach ($categories as $category):
                        $categoryCover = ($category->cover ? image($category->cover, 300) : "");
                        ?>
                        <article class="radius">
                            <div class="thumb">
                                <div style="background-image: url(<?= $categoryCover; ?>);"
                                     class="cover embed radius"></div>
                            </div>
                            <div class="info">
                                <h3 class="title">
                                    <?= $category->title; ?>
                                    [ <b><?= $category->events()->count(); ?> artigos aqui</b> ]
                                </h3>
                                <p class="desc"><?= $category->description; ?></p>

                                <div class="actions">
                                    <a class="icon-pencil btn btn-blue" title=""
                                       href="<?= url("/studio/events/category/{$category->id}"); ?>">Editar</a>

                                    <a class="icon-trash-o btn btn-red" href="#" title=""
                                       data-post="<?= url("/studio/events/category"); ?>"
                                       data-action="delete"
                                       data-confirm="Tem certeza que deseja deletar a categoria?"
                                       data-category_id="<?= $category->id; ?>">Deletar</a>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?= $paginator; ?>
        </section>
    </div>
</section>