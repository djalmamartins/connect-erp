<?php $v->layout("layouts/studio"); ?>
<?php $v->insert("components/results/sidebar.php"); ?>

<section class="dash_content_app">
    <header class="dash_content_app_header">
        <h2 class="icon-trophy">Resultados</h2>
        <a class="icon-plus-circle btn btn-green" href="<?= url("/studio/results/create"); ?>">Importar Arquivos</a>
    </header>

    <div class="dash_content_app_box">
        <section>
            <div class="app_faqs_home">
                <?php if (!$clubs): ?>
                    <div class="message info icon-info">Ainda não existem resultados cadastrados.</div>
                <?php else: ?>
                    <?php foreach ($clubs as $club): ?>
                        <article class="radius">
                            <header>
                                <h3><?= $club->club_name; ?></h3>
                                <p>ID GPC: <b><?= $club->club_gpc_id; ?></b> - Ano: <b><?= $club->year; ?></b></p>
                            </header>
                            <div>

                                <?php if (!$club->events()->count()): ?>
                                    <div class="message info icon-info al-center">
                                        Ainda não existem perguntas
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($club->events()->fetch(true) as $events): ?>
                                        <div class="question radius">
                                            <?= $events->local_id; ?> - <?= $events->local; ?> - <?= $events->year; ?>
                                        </div>
                                    <?php endforeach; ?>

                                <?php endif; ?>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <?= $paginator; ?>
        </section>
    </div>




</section>