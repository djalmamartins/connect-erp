<?php
/** Formulário de lançamento com resumo lateral. @var array $currentPage @var array $spec @var list<array> $fields */
$this->layout('default');
$formId = 'frm-' . strtolower($currentPageId);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Preencha os dados e revise o resumo antes de confirmar.',
]);
$this->insert('components/notifications/proposal');
?>
<div class="erp-grid erp-grid--main">
    <form class="erp-card erp-form" id="<?= $formId ?>" data-erp-validate data-erp-pending="Confirmação pendente: <?= $this->e(mb_strtolower($spec['entity'])) ?> só será gravado(a) com o backend financeiro. Nada foi lançado." aria-labelledby="<?= $formId ?>-title">
        <div class="erp-card__head"><div><h2 id="<?= $formId ?>-title">Dados do lançamento</h2><p>Campos com <span style="color:var(--erp-danger)">*</span> são obrigatórios</p></div></div>
        <div class="erp-form__grid">
            <?php foreach ($fields as $field) { $this->insert('components/forms/field', ['field' => $field]); } ?>
        </div>
        <div class="erp-form__actions">
            <button type="reset" class="erp-btn erp-btn--ghost">Limpar</button>
            <a class="erp-btn" href="<?= erp_url($catalog['groups'][$currentPage['group']]['prefix']) ?>">Cancelar</a>
            <button type="submit" class="erp-btn erp-btn--primary">Confirmar <?= $this->e(mb_strtolower($spec['entity'])) ?></button>
        </div>
    </form>
    <aside class="erp-card" aria-labelledby="<?= $formId ?>-summary">
        <div class="erp-card__head"><div><h2 id="<?= $formId ?>-summary">Resumo</h2><p>Atualizado enquanto você preenche</p></div></div>
        <dl class="erp-detail-grid erp-detail-grid--single" data-erp-summary="<?= $formId ?>">
            <?php foreach (array_slice($fields, 0, 6) as $field): ?><div><dt><?= $this->e($field['label']) ?></dt><dd data-erp-summary-for="<?= $this->e($field['id']) ?>">—</dd></div><?php endforeach; ?>
        </dl>
        <div class="erp-alert" style="margin-top:16px"><?= $icon('info') ?><div>Lançamentos financeiros só são confirmados pelo servidor, com auditoria. Esta tela não simula o resultado.</div></div>
    </aside>
</div>
