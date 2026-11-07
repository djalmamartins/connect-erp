<?php
/** Confirmação de ação destrutiva. Não executa nada sem backend. @var string $modalId @var string $modalTitle @var string $modalText */
?>
<dialog class="erp-modal" id="<?= $this->e($modalId) ?>" aria-labelledby="<?= $this->e($modalId) ?>-title">
    <div class="erp-modal__head">
        <h2 id="<?= $this->e($modalId) ?>-title"><?= $this->e($modalTitle) ?></h2>
        <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
    </div>
    <div class="erp-modal__body">
        <div class="erp-alert erp-alert--danger"><?= $icon('alert') ?><div><strong>Esta ação não pode ser desfeita.</strong><?= $this->e($modalText) ?></div></div>
    </div>
    <div class="erp-modal__foot">
        <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
        <button type="button" class="erp-btn erp-btn--danger" data-erp-pending="Exclusão pendente: o backend deste módulo ainda não foi implementado. Nada foi excluído.">Excluir</button>
    </div>
</dialog>
