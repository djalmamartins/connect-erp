<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/results/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-pencil-square-o">Categorias</h2>
        <a class="icon-plus-circle btn btn-green" href="<?= url("/studio/results/category"); ?>">Nova Categoria</a>
    </header>

    <div class="dash_content_app_box">
        <section>
            <div class="app_blog_categories">
                <?php if (!$categories): ?>
                    <div class="message info icon-info">Ainda não existem categorias cadastradas</div>
                <?php else: ?>
                    <?php foreach ($categories as $category):
                        $categoryCover = ($category->cover ? image($category->cover, 300) : "");
                        ?>
                        <article class="radius">
                            <div class="info">
                                <h3 class="title">
                                    <?= $category->type; ?> - <?= $category->type_name; ?>
                                </h3>
                                <p class="desc"> </p>

                                <div class="actions">
                                    <a class="icon-pencil btn btn-blue" title=""
                                       href="<?= url("/studio/results/category/{$category->id}"); ?>">Editar</a>

                                    <a class="icon-trash btn btn-red" href="#" title=""
                                       data-post="<?= url("/studio/results/category"); ?>"
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