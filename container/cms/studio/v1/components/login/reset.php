<?php
/**
 * Criar senha nova pelo link do e-mail (ADR 0655): a mesma moldura da tela de login.
 * O link leva só o token; a validação fica no Auth::resetWithToken.
 */
$this->layout('layouts/login', ['loadingText' => 'Salvando...']);
$eye = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.5-5 9.5-5 9.5 5 9.5 5-3.5 5-9.5 5-9.5-5-9.5-5z"/><circle cx="12" cy="12" r="2.5"/></svg>';
$lock = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 10h12v10H6zM8.5 10V7.5a3.5 3.5 0 0 1 7 0V10"/></svg>';
?>
<main class="connect-login connect-login--recovery" style="--login-cover:url('<?= theme('/assets/images/bkg_home_connect.jpg') ?>')">
    <?= $this->insert('components/login/_recovery-visual', [
        'visualTitle' => 'Crie a sua<br><strong>senha nova</strong>',
        'visualLead' => 'Escolha uma senha que você não usa em outros sites. Depois de salvar, entre com ela normalmente.',
        'tips' => [
            ['key', 'Pelo menos 8 caracteres', 'Misture letras, números e símbolos para ficar mais forte.'],
            ['shield', 'Só sua', 'Não use a mesma senha do e-mail, do banco ou de redes sociais.'],
            ['clock', 'Link de uso único', 'Depois de salvar, este link deixa de funcionar.'],
        ],
    ]) ?>
    <section class="connect-login__access">
        <div class="connect-login__card">
            <header>
                <img src="<?= theme('/assets/images/logo-connect-condominios.svg') ?>" alt="Connect Condomínios">
                <span><?= $loginEyebrow ?></span>
                <h2>Criar senha nova</h2>
                <p>Mínimo de 8 caracteres</p>
            </header>
            <div class="ajax_response" role="status" aria-live="polite"><?= flash() ?></div>
            <form action="<?= url('/reset') ?>" method="post" autocomplete="off">
                <?= csrf_input() ?>
                <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
                <label><span>Senha nova</span><span class="connect-login__field"><?= $lock ?><input id="resetPassword" name="password" type="password" placeholder="Crie uma senha" autocomplete="new-password" minlength="8" required autofocus><button class="connect-login__eye" type="button" aria-label="Mostrar senha" aria-controls="resetPassword"><?= $eye ?></button></span></label>
                <label><span>Repita a senha</span><span class="connect-login__field"><?= $lock ?><input id="resetPasswordRe" name="password_re" type="password" placeholder="Repita a senha nova" autocomplete="new-password" minlength="8" required><button class="connect-login__eye" type="button" aria-label="Mostrar senha" aria-controls="resetPasswordRe"><?= $eye ?></button></span></label>
                <div class="connect-login__options"><span>Área restrita</span><a href="<?= htmlspecialchars($loginBack) ?>">Voltar para o login</a></div>
                <button class="connect-login__submit" type="submit"><span>Salvar senha</span><strong>→</strong></button>
            </form>
            <footer><span class="connect-login__lock" aria-hidden="true"><svg viewBox="0 0 24 24"><rect x="5" y="10" width="14" height="11" rx="2"></rect><path d="M8 10V7a4 4 0 0 1 8 0v3"></path></svg></span> Ambiente seguro e monitorado</footer>
        </div>
        <a class="connect-login__back" href="<?= url('/') ?>">← Voltar ao site</a>
    </section>
</main>
<?php $this->start('scripts') ?><script>(function(){document.querySelectorAll('.connect-login__eye').forEach(function(b){var p=document.getElementById(b.getAttribute('aria-controls'));if(!p)return;b.addEventListener('click',function(){var v=p.type==='text';p.type=v?'password':'text';b.setAttribute('aria-label',v?'Mostrar senha':'Ocultar senha');b.classList.toggle('is-visible',!v);});});})();</script><?php $this->stop() ?>
