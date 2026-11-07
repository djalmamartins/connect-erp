<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/results/sidebar.php"); ?>

<section class="dash_content_app">
    <?php if (!$category): ?>
        <header class="dash_content_app_header">
            <h2 class="icon-plus-circle">Nova Categoria</h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/results/category"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="create"/>

                <label class="label">
                    <span class="legend">*Nome:</span>
                    <input type="text" name="type_name" placeholder="O nome da categoria" required/>
                </label>

                <label class="label">
                    <span class="legend">*Abreviação:</span>
                    <input type="text" name="type" placeholder="Vogal que representa a categoria" required/>
                </label>

                <div class="al-right">
                    <button class="btn btn-green icon-check-square-o">Criar Categoria</button>
                </div>
            </form>
        </div>
    <?php else: ?>
        <header class="dash_content_app_header">
            <h2 class="icon-bookmark-o"><?= $category->type_name; ?></h2>
        </header>

        <div class="dash_content_app_box">
            <form class="app_form" action="<?= url("/studio/results/category/{$category->id}"); ?>" method="post">
                <!--ACTION SPOOFING-->
                <input type="hidden" name="action" value="update"/>

                <label class="label">
                    <span class="legend">*Nome:</span>
                    <input type="text" name="type_name" placeholder="O nome da categoria" value="<?= $category->type_name; ?>" required/>
                </label>

                <label class="label">
                    <span class="legend">*Abreviação:</span>
                    <input type="text" name="type" placeholder="Vogal que representa a categoria" value="<?= $category->type; ?>" required/>
                </label>


                <div class="al-right">
                    <button class="btn btn-blue icon-check-square-o">Atualizar</button>
                </div>
            </form>
        </div>
    <?php endif; ?>
</section>