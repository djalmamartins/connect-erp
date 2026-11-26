<?php
/**
 * Esqueci minha senha (ADR 0655): a mesma moldura da tela de login (foto, painel e cartão).
 * Serve ERP, Studio, Help Desk e app do morador; $loginBack leva de volta ao login de origem.
 */
$this->layout('layouts/login', ['loadingText' => 'Enviando...']);
?>
<main class="connect-login connect-login--recovery" style="--login-cover:url('<?= theme('/assets/images/bkg_home_connect.jpg') ?>')">
    <?= $this->insert('components/login/_recovery-visual', [
        'visualTitle' => 'Esqueceu a senha?<br><strong>A gente ajuda</strong>',
        'visualLead' => 'Informe o e-mail ou CPF da sua conta. Se ele estiver cadastrado, você recebe um link para criar uma senha nova.',
        'tips' => [
            ['mail', 'Confira o e-mail', 'O link chega em poucos minutos. Veja também a caixa de spam e promoções.'],
            ['clock', 'Link com prazo', 'Por segurança, o link vale por pouco tempo e só pode ser usado uma vez.'],
            ['shield', 'Não pediu a troca?', 'Ignore o e-mail. Sua senha atual continua valendo.'],
        ],
    ]) ?>
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span><?= $loginEyebrow ?></span>
                <h2>Recuperar senha</h2>
                <p>Enviaremos um link para criar uma senha nova</p>
            </header>
            <div class="ajax_response" role="status" aria-live="polite"><?= flash() ?></div>
            <form action="<?= url('/forget') ?>" method="post" data-reset="true">
                <?= csrf_input() ?>
                <label><span>E-mail ou CPF</span><span class="connect-login__field"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6.5h18v12H3zM3.5 7l8.5 7 8.5-7"/></svg><input name="email" type="text" placeholder="seu@email.com ou CPF" autocomplete="username" autocapitalize="off" spellcheck="false" required autofocus></span></label>
                <div class="connect-login__options"><span>Área restrita</span><a href="<?= htmlspecialchars($loginBack) ?>">Voltar para o login</a></div>
                <button class="connect-login__submit" type="submit"><span>Enviar link</span><strong>→</strong></button>
            </form>
            <footer><span class="connect-login__lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span> Ambiente seguro e monitorado</footer>
        </div>
        <a class="connect-login__back" href="<?= url('/') ?>">← Voltar ao site</a>
    </section>
</main>
