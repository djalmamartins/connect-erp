<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/associations/sidebar.php"); ?>

<section class="dash_content_app">

        <header class="dash_content_app_header">
            <h2 class="icon-print">Imprimir</h2>
            <a class="icon-print btn btn-blue" target="_blank" href="<?= url("/studio/users/report/{$print}"); ?>">Imprimir relatório</a>
        </header>

            <?php $v->insert("components/associations/list.php"); ?>
        </div>
</section>