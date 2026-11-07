<?php
/** Estados de acesso negado (403) e não encontrado (404) do ERP. @var string $title @var string $description */
$this->layout('default');
?>
<section class="erp-state erp-state-error" role="alert">
    <h1><?= $this->e($title) ?></h1>
    <p><?= $this->e($description) ?></p>
    <p><a href="<?= url('/erp/condominios') ?>">Voltar para Condomínios</a></p>
</section>
