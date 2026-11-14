<?php /** Tela sem conexão (service worker, ADR 0302). */ $this->layout('partials/bare'); ?>
<main class="app-login" id="app-main">
    <h1>Sem conexão</h1>
    <p class="app-login__lead">Confira a internet do celular e tente de novo.</p>
    <button type="button" class="app-button" onclick="location.reload()">Tentar de novo</button>
</main>
