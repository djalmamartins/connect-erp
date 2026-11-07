<?php $this->layout("layouts/error"); ?>

<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1></h1>
            </header>
            <div class="main_login_form">
                <?= flash(); ?>
                <article>
                    <form name="login" action="<?= url("/login"); ?>" method="post" enctype="multipart/form-data">
                        <?= csrf_input(); ?>
                        <label>
                            <div><span class="icon-user">Email, CPF ou CNPJ:</span></div>
                            <input type="text" id="cpf-email" name="email" value="<?= ($cookie ?? null); ?>" placeholder="Informe seu Email, CPF ou CNPJ:"
                                   required/>
                        </label>
                        <label>
                            <div>
                                <span class="icon-lock">Senha:</span>
                                <a title="Esqueceu a senha?"
                                   href="<?= url("/forget"); ?>">Esqueceu a senha?</a>
                            </div>

                            <div class="gruop_pass">
                                <input type="password" name="password" id="password" placeholder="Informe sua senha:" required/>
                                <span class="view-password icon-notext icon-lock"></span>
                            </div>
                        </label>

                        <label class="check">
                            <input type="checkbox" <?= (!empty($cookie) ? "checked" : ""); ?> name="save"/>
                            <span>Lembrar dados?</span>
                        </label>

                        <button class="btn btn-full gradient gradient-web gradient-hover transition icon-sign-in">Entrar
                        </button>
                    </form>
                </article>
            </div>
        </div>
    </div>
</article>
