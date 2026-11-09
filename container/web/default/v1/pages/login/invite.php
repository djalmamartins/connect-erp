<?php $this->layout("layouts/error"); ?>
<?php /** Aceite do convite do morador (ADR 0092). Formulário comum (sem ajax); o token não aparece na página. */ ?>
<article class="main_login">
    <div class="container main_login_page">
        <header class="login_logo">
            <img src="<?= theme("/assets/images/marca_connect.svg"); ?>" alt="Connect Condomínios">
        </header>
        <div class="main_login_box">
            <header class="al-center">
                <h1>Ative sua conta</h1>
            </header>
            <div class="main_login_form">
                <article>
                    <div class="ajax_response"><?= flash(); ?></div>
                    <?php if ($invite === null): ?>
                        <p class="al-center" id="invite_generic"><?= $this->e($generic); ?></p>
                        <?php /* ADR 0112: o link do e-mail leva o token depois do "#", que o navegador não envia ao servidor
                           nem grava no log de acesso. Aqui ele sai do endereço e vai num POST com CSRF. */ ?>
                        <form id="invite_claim" action="<?= url("/convite/abrir"); ?>" method="post" hidden>
                            <?= csrf_input(); ?>
                            <input type="hidden" name="token" value=""/>
                        </form>
                        <script>
                            (function () {
                                var token = (window.location.hash || '').slice(1);
                                if (!/^[0-9a-f]{64}$/.test(token)) { return; }
                                history.replaceState(null, '', window.location.pathname);
                                var form = document.getElementById('invite_claim');
                                form.elements.token.value = token;
                                document.getElementById('invite_generic').textContent = 'Abrindo o convite...';
                                form.submit();
                            })();
                        </script>
                    <?php else: ?>
                        <p>Olá, <?= $this->e($invite['name']); ?>. Crie a senha da conta <strong><?= $this->e($invite['email']); ?></strong>.</p>
                        <form action="<?= url("/convite"); ?>" method="post" autocomplete="off">
                            <?= csrf_input(); ?>
                            <label>
                                <div><span class="icon-id-card">CPF ou CNPJ do seu cadastro:</span></div>
                                <input type="text" name="document" inputmode="numeric" maxlength="18" required/>
                            </label>
                            <label>
                                <div>
                                    <span class="icon-lock">Crie uma senha:</span>
                                    <span><small>(Mínimo <?= (int)CONF_PASSWD_MIN_LEN; ?> caracteres)</small></span>
                                </div>
                                <div class="gruop_pass">
                                    <input type="password" name="password" autocomplete="new-password" required/>
                                </div>
                            </label>
                            <label>
                                <div><span class="icon-lock">Repita a senha:</span></div>
                                <div class="gruop_pass">
                                    <input type="password" name="password_re" autocomplete="new-password" required/>
                                </div>
                            </label>
                            <button class="btn btn-full gradient gradient-web gradient-hover transition icon-check-square-o">Ativar conta</button>
                        </form>
                    <?php endif; ?>
                </article>
            </div>
        </div>
    </div>
</article>
