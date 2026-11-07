<?php
$this->layout("layouts/error"); ?>

<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1>Recuperar senha</h1>
            </header>
            <div class="main_login_form">
                <?= flash(); ?>
                <article>
                    <div class="ajax_response"><?= flash(); ?></div>
                    <form class="login" data-reset="true" action="<?= url("/forget"); ?>" method="post"
                          enctype="multipart/form-data">
                        <?= csrf_input(); ?>
                        <label>
                            <div>
                                <span class="icon-user">Email ou CPF:</span>
                                <span><a title="Voltar e entrar!"
                                         href="<?= url("/studio/login"); ?>">Voltar e entrar!</a></span>
                            </div>
                            <input type="text" id="cpf-email" name="email" placeholder="Informe seu E-mail ou CPF:"
                                   required/>
                        </label>

                        <button class="btn btn-full gradient gradient-web gradient-hover transition icon-paper-plane-o">
                            Enviar
                        </button>
                    </form>
                </article>
            </div>
        </div>
    </div>
</article>
