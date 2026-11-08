<?php
/** Estados de acesso negado (403) e não encontrado (404) de Condomínios. @var string $title @var string $description */
$this->layout('default');
?>
<section class="erp-card" style="max-width:640px;margin:48px auto" role="alert">
    <div class="erp-empty">
        <span class="erp-empty__icon"><?= $icon('alert') ?></span>
        <h1 style="font-size:18px"><?= $this->e($title) ?></h1>
        <p><?= $this->e($description) ?></p>
        <a class="erp-btn erp-btn--primary" href="<?= url('/erp/condominios') ?>">Voltar para Condomínios</a>
    </div>
</section>
