<?php $this->layout('layouts/login'); ?>
<main class="connect-login connect-login--compact">
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span>RECUPERAÇÃO DE SENHA</span>
                <h2>Recuperação indisponível no momento</h2>
                <p>O envio do link de redefinição por e-mail ainda não está configurado neste ambiente. Para redefinir sua senha, fale com o administrador da Connect.</p>
            </header>
            <footer><a href="<?= url(($adminBase ?? '/studio') . '/login') ?>">← Voltar para o login</a></footer>
        </div>
    </section>
</main>
