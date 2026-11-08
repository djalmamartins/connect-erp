<?php
/** Importação em três etapas: arquivo, pré-visualização, resultado. @var array $currentPage @var array $spec @var list<array> $columns @var array $rows */
$this->layout('default');
$id = 'imp-' . strtolower($currentPageId);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'],
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Envie o arquivo, confira as linhas reconhecidas e só então confirme a importação.',
]);
$this->insert('components/notifications/proposal');
?>
<ol class="erp-steps" aria-label="Etapas">
    <li class="is-current"><span>1</span>Arquivo</li>
    <li><span>2</span>Pré-visualização</li>
    <li><span>3</span>Resultado</li>
</ol>
<div class="erp-grid erp-grid--main">
    <form class="erp-card erp-form" data-erp-validate data-erp-pending="Importação pendente: o processamento do arquivo será feito pelo servidor quando o módulo estiver integrado. Nada foi importado.">
        <div class="erp-card__head"><div><h2><?= $this->e($spec['fileLabel']) ?></h2><p>Formatos aceitos: <?= $this->e(str_replace(',', ', ', $spec['accept'])) ?></p></div></div>
        <div class="erp-form__grid">
            <div class="erp-field erp-col-6"><label for="<?= $id ?>-condo">Condomínio <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label><select class="erp-select" id="<?= $id ?>-condo" required><option value="">Selecione</option><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select><span class="erp-field__error" aria-live="polite"></span></div>
            <div class="erp-field erp-col-6"><label for="<?= $id ?>-file">Arquivo <span aria-hidden="true" style="color:var(--erp-danger)">*</span></label><input class="erp-input" type="file" id="<?= $id ?>-file" accept="<?= $this->e($spec['accept']) ?>" required><span class="erp-field__hint">Tamanho máximo: 10 MB.</span><span class="erp-field__error" aria-live="polite"></span></div>
        </div>
        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('upload', 'erp-icon--sm') ?>Validar arquivo</button></div>
    </form>
    <aside class="erp-card">
        <div class="erp-card__head"><div><h2>Antes de importar</h2></div></div>
        <ul class="erp-list">
            <li><span class="erp-list__icon"><?= $icon('check', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>O mesmo arquivo não é importado duas vezes</strong><small>Arquivos repetidos são recusados</small></div></li>
            <li><span class="erp-list__icon"><?= $icon('check', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Linhas com erro não entram</strong><small>O relatório mostra o motivo de cada uma</small></div></li>
            <li><span class="erp-list__icon"><?= $icon('check', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Nada muda até a confirmação</strong><small>A pré-visualização não grava dados</small></div></li>
        </ul>
    </aside>
</div>
<section class="erp-card erp-card--flush" aria-labelledby="<?= $id ?>-preview">
    <div class="erp-card__head"><div><h2 id="<?= $id ?>-preview">Pré-visualização</h2><p><?= $rows !== [] ? 'Exemplo de como as linhas reconhecidas aparecerão' : 'Envie um arquivo para ver as linhas' ?></p></div><?php if ($rows !== []): ?><span class="erp-demo-flag">Demonstração</span><?php endif; ?></div>
    <?php $this->insert('components/tables/data-table', ['tableId' => $id . '-rows', 'tableCaption' => 'Pré-visualização da importação', 'tableColumns' => $columns, 'tableRows' => $rows, 'tableActions' => false]); ?>
</section>
