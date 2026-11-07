<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/users/sidebar.php"); ?>

<section class="studio-page-header">
    <div>
        <p class="studio-eyebrow">Gestão</p>
        <h1 class="studio-page-title">Usuários</h1>
        <p class="studio-page-description">Gerencie usuários, perfis e acessos do Studio em um só lugar.</p>
    </div>
    <form action="<?= url($adminBase . "/users"); ?>" class="studio-page-actions studio-user-search">
        <input class="studio-input" type="search" name="s" value="<?= htmlspecialchars((string)$search); ?>" placeholder="Pesquisar usuário">
        <button class="studio-btn icon" type="submit" aria-label="Pesquisar"><ion-icon name="search-outline"></ion-icon></button>
    </form>
</section>

<section class="dash_content_app studio-users-legacy">
    <div class="dash_content_app_box">
        <section>
            <div class="app_users_home">
                <?php foreach ($users as $user):
                    $userPhoto = ($user->photo() ? image($user->photo, 300, 300) :
                        theme("/assets/images/avatar.jpg", CONF_VIEW_STUDIO));
                    ?>
                    <article class="user radius">
                        <div class="cover" style="background-image: url(<?= $userPhoto; ?>)"></div>
                        <?php if ($user->level >= 10): ?>
                            <p class="level icon-life-ring">Agência Moves</p>
                        <?php elseif ($user->level >= 5): ?>
                            <p class="level icon-life-ring">Administrador</p>
                        <?php else: ?>
                            <p class="level icon-user">USUÁRIO</p>
                        <?php endif; ?>

                        <h4><?= $user->first_name; ?></h4>
                        <div class="info">
                            <p><?= $user->email; ?></p>
                            <p>Desde <?= date_fmt($user->created_at, "d/m/y \à\s H\hi"); ?></p>
                        </div>

                        <div class="actions">
                            <a class="icon-cog btn btn-blue" href="<?= url($adminBase . "/users/user/{$user->id}"); ?>"
                               title="">Gerenciar</a>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>

            <?= $paginator; ?>
        </section>
    </div>
</section>