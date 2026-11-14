<?php
/** Login do app do morador (ADR 0302). @var string $head @var string $email */
$this->layout('partials/bare');
?>
<main class="app-login" id="app-main">
    <img class="app-login__logo" src="<?= $this->e(site_logo_url()) ?>" alt="Connect Condomínios" width="180" height="48">
    <h1>Aplicativo do morador</h1>
    <p class="app-login__lead">Boletos, reservas, encomendas, visitantes e avisos do seu condomínio.</p>
    <?php if ($appFlash = flash()): ?><div class="app-flash" role="status"><?= $appFlash ?></div><?php endif; ?>
    <form method="post" action="<?= app_url('/login') ?>" class="app-form app-card">
        <?= csrf_input() ?>
        <label class="app-field">E-mail <input type="email" name="email" value="<?= $this->e($email) ?>" autocomplete="username" required></label>
        <label class="app-field">Senha <input type="password" name="password" autocomplete="current-password" required></label>
        <label class="app-check"><input type="checkbox" name="save" value="1"<?= $email !== '' ? ' checked' : '' ?>> Lembrar meu e-mail</label>
        <button type="submit" class="app-button app-button--block">Entrar</button>
        <a class="app-login__forgot" href="<?= url('/forget') ?>">Esqueci minha senha</a>
    </form>
    <p class="app-login__note">Ainda não tem acesso? A conta é criada pelo convite que a administração envia por e-mail.</p>
</main>
