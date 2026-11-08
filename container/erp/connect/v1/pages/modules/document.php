<?php
/** Emissão de documento: parâmetros e pré-visualização. @var array $currentPage @var array $spec */
$this->layout('default');
$id = 'doc-' . strtolower($currentPageId);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Escolha a unidade, confira a prévia e emita o documento.',
]);
$this->insert('components/notifications/proposal');
?>
<div class="erp-grid erp-grid--2">
    <form class="erp-card erp-form" data-erp-validate data-erp-pending="Emissão pendente: o documento só será emitido em PDF com os dados reais da unidade. Nada foi emitido.">
        <div class="erp-card__head"><div><h2>Dados do documento</h2></div></div>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-12"><label for="<?= $id ?>-condo">Condomínio <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label><select class="erp-select" id="<?= $id ?>-condo" required><option value="">Selecione</option><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select><span class="erp-field__error" aria-live="polite"></span></div>
            <div class="erp-field erp-col-6"><label for="<?= $id ?>-unit">Unidade <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label><input class="erp-input" id="<?= $id ?>-unit" required maxlength="20" placeholder="Ex.: 304-B"><span class="erp-field__error" aria-live="polite"></span></div>
            <div class="erp-field erp-col-6"><label for="<?= $id ?>-date">Data de referência</label><input class="erp-input" type="date" id="<?= $id ?>-date" value="2026-11-07"></div>
            <div class="erp-field erp-col-12"><label for="<?= $id ?>-obs">Observações</label><textarea class="erp-textarea" id="<?= $id ?>-obs" maxlength="1000"></textarea></div>
        </div>
        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('file', 'erp-icon--sm') ?>Emitir documento</button></div>
    </form>
    <section class="erp-card erp-doc-preview" aria-labelledby="<?= $id ?>-title">
        <span class="erp-demo-flag">Prévia · sem validade</span>
        <h2 id="<?= $id ?>-title"><?= $this->e($spec['docTitle']) ?></h2>
        <p><?= $this->e($spec['docText']) ?></p>
        <dl class="erp-detail-grid">
            <div><dt>Condomínio</dt><dd>Selecione ao lado</dd></div>
            <div><dt>Unidade</dt><dd>—</dd></div>
            <div><dt>Emitido por</dt><dd>Connect · administradora</dd></div>
            <div><dt>Data</dt><dd><?= date('d/m/Y') ?></dd></div>
        </dl>
        <p class="erp-field__hint">O documento final terá código de verificação e assinatura da administradora.</p>
    </section>
</div>
