<?php $this->layout("layouts/error"); ?>

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
                <article>
                    <div class="ajax_response"><?= flash(); ?></div>
                    <form class="login" action="<?= url("/reset"); ?>" method="post" enctype="multipart/form-data">
                        <input type="hidden" name="code" value="<?= $code; ?>"/>
                        <?= csrf_input(); ?>
                        <label>
                            <div>
                                <span class="icon-lock">Defina uma senha:</span>
                                <span><small>(Mínimo 8 caracteres)</small></span>
                            </div>
                            <div class="gruop_pass">
                                <input type="password" name="password" id="password" placeholder="Crie uma senha:" required/>
                                <span class="view-password icon-notext icon-lock"></span>
                            </div>
                        </label>
                        <label>
                            <div><span class="icon-lock">Confirme sua nova senha:</span></div>
                            <div class="gruop_pass">
                                <input type="password" name="password_re" id="password_re" placeholder="Repita a nova senha:" required/>
                            </div>
                        </label>

                        <button class="btn btn-full gradient gradient-web gradient-hover transition icon-check-square-o">Confirmar</button>
                    </form>
                </article>
            </div>
        </div>
    </div>
</article>
