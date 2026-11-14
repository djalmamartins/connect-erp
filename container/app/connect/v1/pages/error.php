<?php /** Erro do app do morador (ADR 0302). @var int $code */ $this->layout('partials/bare'); ?>
<main class="app-login" id="app-main">
    <h1><?= $code === 404 ? 'Página não encontrada' : 'Algo deu errado' ?></h1>
    <p class="app-login__lead"><?= $code === 404 ? 'O endereço não existe no aplicativo.' : 'Tente de novo em alguns minutos.' ?></p>
    <a class="app-button" href="<?= app_url('/') ?>">Ir para o início</a>
</main>
