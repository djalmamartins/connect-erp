<?php
/**
 * Modal de cadastro/edição com validação no navegador. O envio não grava: avisa que o
 * backend do módulo está pendente.
 * @var string $modalId @var string $modalTitle @var list<array> $modalFields
 */
?>
<dialog class="erp-modal erp-modal--wide" id="<?= $this->e($modalId) ?>" aria-labelledby="<?= $this->e($modalId) ?>-title">
    <form method="dialog" class="erp-form" data-erp-validate data-erp-pending="Gravação pendente: o backend deste módulo ainda não foi implementado. Nada foi salvo.">
        <div class="erp-modal__head">
            <h2 id="<?= $this->e($modalId) ?>-title"><?= $this->e($modalTitle) ?></h2>
            <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
        </div>
        <div class="erp-modal__body">
            <div class="erp-form__grid">
                <?php foreach ($modalFields as $field) { $this->insert('components/forms/field', ['field' => $field]); } ?>
            </div>
        </div>
        <div class="erp-modal__foot">
            <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
            <button type="submit" class="erp-btn erp-btn--primary">Salvar</button>
        </div>
    </form>
</dialog>
