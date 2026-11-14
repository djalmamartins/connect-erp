<?php /** Função desligada na matriz do portal ou fora do tipo de vínculo (ADR 0302). @var string $feature */ $this->layout('default'); ?>
<div class="app-card app-empty">
    <h1><?= $this->e($feature) ?></h1>
    <p>Esta função ainda não foi liberada para você pelo seu condomínio. Quando a administração ligar, ela aparece no menu.</p>
    <p><a class="app-button app-button--ghost" href="<?= app_url('/') ?>">Voltar ao início</a></p>
</div>
