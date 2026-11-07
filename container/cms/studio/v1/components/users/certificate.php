<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/users/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-user">Certificados</h2>
        <form action="<?= url($adminBase . "/users/certificate"); ?>" class="app_search_form">
            <input type="text" name="s" value="<?= $search; ?>" placeholder="Pesquisar certificado:">
            <button class="icon-search icon-notext"></button>
        </form>
    </header>
    <div class="dash_content_app_box_print">
        <div class="one">
            <?php if (!$users): ?>
                <div class="message info icon-info">Ainda não existem certificados cadastrados.</div>
            <?php else: ?>
                <div class="app_launch_item header">
                    <p class="itemName">Nome:</p>
                    <p class="item">Certificado:</p>
                    <p class="item">Validade:</p>
                </div>
                <?php foreach ($users as $user): ?>
                    <article class="app_launch_item">
                        <p class="itemName"><?= $user->first_name; ?></p>
                        <?php if (!$user->certificates()->count()): ?>
                            <div class="itemName icon-info al-center">
                                Ainda não emitiu o certificado.
                            </div>
                        <?php else: ?>
                            <?php foreach ($user->certificates()->fetch(true) as $certificate): ?>
                                <p class="item"><?= $certificate->register; ?></p>
                                <p class="item"><?= date_br_fmt($certificate->next_due); ?></p><br>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?= $paginator; ?>
    </div>
</section>