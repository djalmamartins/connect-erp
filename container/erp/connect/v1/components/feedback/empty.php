<?php
/** Estado vazio: motivo, impacto e próximo passo. @var string $emptyTitle @var string $emptyText */
?>
<div class="erp-empty" role="status">
    <span class="erp-empty__icon"><?= $icon($emptyIcon ?? 'info') ?></span>
    <h3><?= $this->e($emptyTitle) ?></h3>
    <p><?= $this->e($emptyText) ?></p>
</div>
