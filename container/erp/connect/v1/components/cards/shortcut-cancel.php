<?php
/**
 * Diálogo de cancelamento com motivo dos lançamentos avulsos (ADR 0057 e 0073): multa, cobrança extra
 * e transferência. Os botões [data-cancel-open] trazem data-api (POST .../cancel) e data-label; o
 * erp.js (data-erp-shortcuts) envia {reason} e mostra o erro dentro do diálogo.
 * @var bool $canManage @var string $title @var string $hint
 */
if (!$canManage) {
    return;
}
?>
<dialog class="erp-modal" id="shortcut-cancel" aria-labelledby="shortcut-cancel-title">
    <form data-cancel-form novalidate>
        <div class="erp-modal__head"><h2 id="shortcut-cancel-title"><?= $this->e($title) ?></h2><button type="button" class="erp-btn erp-btn--ghost erp-btn--sm" data-erp-modal-close aria-label="Fechar"><?= $icon('close', 'erp-icon--sm') ?></button></div>
        <div class="erp-modal__body">
            <p><strong data-cancel-label></strong></p>
            <p class="erp-muted"><?= $this->e($hint) ?></p>
            <div class="erp-field"><label for="shortcut-cancel-reason">Motivo do cancelamento</label><textarea class="erp-textarea" id="shortcut-cancel-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: lançado em duplicidade"></textarea></div>
            <div data-form-error aria-live="assertive"></div>
        </div>
        <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger"><?= $this->e($title) ?></button></div>
    </form>
</dialog>
