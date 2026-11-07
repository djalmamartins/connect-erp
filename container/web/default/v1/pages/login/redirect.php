<?php

$this->layout("layouts/error"); ?>

<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1>Meus Serviços</h1>
            </header>
            <div class="main_login_form">
                <?= flash(); ?>
                <article>
                    <div class="ajax_response"><?= flash(); ?></div>

                    <article class="redirect">
                        <div class="icon-notext icon-star-o"></div>
                        <span>
                            <b>Condomínios do Bloco 10</b>
                            <small>Connect Condomínios</small>
                        </span>
                        <a class="icon-notext icon-chevron-right link" href="<?= url("/app"); ?>"></a>
                    </article>

                    <article class="redirect">
                        <div class="icon-notext icon-dollar"></div>
                        <span>
                            <b>ERP</b>
                            <small>Connect Condomínios</small>
                        </span>
                            <a class="icon-notext icon-chevron-right link" href="<?= url("/erp"); ?>"></a>
                    </article>


                </article>
            </div>
        </div>
    </div>
</article>