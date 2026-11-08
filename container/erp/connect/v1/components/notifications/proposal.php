<?php
/** Aviso de interface proposta e dados de demonstração. @var array $currentPage @var string $currentPageId @var bool $demo */
?>
<div class="erp-alert erp-alert--warning" role="note">
    <?= $icon('info') ?>
    <div><strong>Interface proposta<?= $demo ? ' com dados de demonstração' : '' ?></strong>Campos e colunas seguem o nome da página e ainda serão confirmados na ficha <?= $this->e($currentPageId) ?> (<?= $this->e($currentPage['spec']) ?>). Ações que gravam ou enviam dados ficam pendentes até o backend do módulo.</div>
</div>
