<?php
/**
 * Continuação de um registro do Operacional (ADR 0053 e 0070): "Chamar lista de espera" na reserva
 * cancelada e "Gerar próxima" na manutenção concluída e recorrente. O botão chama a API (erp.js,
 * data-erp-record-action) e o resultado aparece no próprio card.
 * @var array{kind:string, title:string, text:string, label:string, api:string, route:string} $action
 */
?>
<section class="erp-card" aria-labelledby="record-action-title" data-erp-record-action data-kind="<?= $this->e($action['kind']) ?>" data-api="<?= $this->e($action['api']) ?>" data-route="<?= $this->e($action['route']) ?>">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="record-action-title"><?= $this->e($action['title']) ?></h2></div></div>
    <p class="erp-field__hint"><?= $this->e($action['text']) ?></p>
    <div data-record-action-result aria-live="polite"></div>
    <button type="button" class="erp-btn erp-btn--primary erp-btn--block" data-record-action-run><?= $icon($action['kind'] === 'waitlist' ? 'users' : 'calendar', 'erp-icon--sm') ?><?= $this->e($action['label']) ?></button>
</section>
