<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/associations/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-university">Associações</h2>
        <form action="<?= url("/studio/associations/home"); ?>" class="app_search_form">
            <input type="text" name="s" value="<?= $search; ?>" placeholder="Pesquisar Associação:">
            <button class="icon-search icon-notext"></button>
        </form>
    </header>

    <div class="dash_content_app_box">
        <section>
            <div class="app_users_home">
                <?php foreach ($clubs as $club):
                    $clubPhoto = ($club->photoCompany() ? image($club->photo, 300, 300) :
                        theme("/assets/images/avatar.jpg", CONF_VIEW_STUDIO));
                    ?>
                <?php if($club->id == 1): ?>


                <?php else: ?>
                    <article class="user radius">
                        <div class="cover" style="background-image: url(<?= $clubPhoto; ?>)"></div>

                        <h4><?= $club->company_name; ?> - <?= $club->initials_name; ?></h4>
                        <div class="info">
                            <p><?= $club->email; ?></p>
                            <p>Desde <?= date_fmt($club->created_at, "d/m/y \à\s H\hi"); ?></p>
                        </div>

                        <div class="actions">
                            <a class="icon-cog btn btn-blue" href="<?= url("/studio/associations/association/{$club->id}"); ?>"
                               title="">Gerenciar</a>
                        </div>
                    </article>
                <?php endif; ?>

                <?php endforeach; ?>
            </div>

            <?= $paginator; ?>
        </section>
    </div>
</section>