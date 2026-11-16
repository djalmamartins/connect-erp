<?php
/**
 * Atalhos da lista de lançamentos (AP-020, revisão de 15/11 item 17, modelo 7, ADR 0313): nova receita, nova despesa,
 * desconto, importar, cancelados e, com um condomínio escolhido, copiar e excluir (cancelar) os lançamentos marcados.
 * Copiar vai para POST /api/v1/entries/copy e excluir para POST /api/v1/entries/cancel (ADR 0203), que conferem tudo de
 * novo (erp.js, data-entry-shortcuts). Excluir não apaga: cancela com motivo e a auditoria guarda o antes.
 * @var int $entryCondominium @var string $cancelledHref
 */
$picks = $entryCondominium > 0;
$links = [
    ['/financeiro/lancar-receita', 'arrow-down', 'Nova receita', 'erp-btn--primary'],
    ['/financeiro/lancar-despesa', 'arrow-up', 'Nova despesa', ''],
    ['/financeiro/lancar-desconto', 'receipt', 'Desconto', ''],
    ['/importacoes/lancamentos-planilha', 'upload', 'Importar', ''],
];
?>
<div class="erp-entry-shortcuts" data-entry-shortcuts data-condominium="<?= (int)$entryCondominium ?>" role="toolbar" aria-label="Atalhos dos lançamentos">
    <?php foreach ($links as [$href, $iconName, $label, $tone]): ?>
        <a class="erp-btn erp-btn--sm <?= $tone ?>" href="<?= $this->e(erp_url($href)) ?>"><?= $icon($iconName, 'erp-icon--sm') ?><?= $label ?></a>
    <?php endforeach; ?>
    <a class="erp-btn erp-btn--sm" href="<?= $this->e($cancelledHref) ?>" title="Lançamentos cancelados (histórico)"><?= $icon('clock', 'erp-icon--sm') ?>Histórico</a>
    <span class="erp-entry-shortcuts__sep" aria-hidden="true"></span>
    <?php if ($picks): ?>
        <span class="erp-muted" data-entry-picked>Marque lançamentos na lista para copiar ou excluir.</span>
        <button type="button" class="erp-btn erp-btn--sm" data-erp-modal-open="entry-copy-dialog" data-entry-needs-pick disabled><?= $icon('file', 'erp-icon--sm') ?>Copiar lançamento(s)</button>
        <button type="button" class="erp-btn erp-btn--sm erp-btn--danger" data-erp-modal-open="entry-cancel-dialog" data-entry-needs-pick disabled><?= $icon('trash', 'erp-icon--sm') ?>Excluir lançamentos</button>
    <?php else: ?>
        <span class="erp-muted">Para copiar ou excluir vários, escolha um condomínio na barra superior.</span>
    <?php endif; ?>
</div>
<?php if ($picks): ?>
<dialog class="erp-modal" id="entry-copy-dialog" aria-labelledby="entry-copy-title">
    <form method="dialog" novalidate data-entry-copy-form data-api="<?= $this->e(url('/api/v1/entries/copy')) ?>">
        <div class="erp-modal__head"><h2 id="entry-copy-title">Copiar lançamentos</h2><button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button></div>
        <div class="erp-modal__body erp-stack">
            <p class="erp-muted" data-entry-pick-count></p>
            <div data-form-error aria-live="assertive"></div>
            <div class="erp-field"><label for="entry-copy-month">Copiar para a competência</label><input class="erp-input" type="month" id="entry-copy-month" name="competence" value="<?= date('Y-m', strtotime('first day of next month')) ?>" required></div>
            <p class="erp-field__hint">As cópias nascem em aberto, com os mesmos dados e o vencimento andando os mesmos meses da competência. Não levam baixa, boleto nem a origem (fatura, acordo).</p>
        </div>
        <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('file', 'erp-icon--sm') ?>Copiar</button></div>
    </form>
</dialog>
<dialog class="erp-modal" id="entry-cancel-dialog" aria-labelledby="entry-cancel-title">
    <form method="dialog" novalidate data-entry-cancel-form data-api="<?= $this->e(url('/api/v1/entries/cancel')) ?>">
        <div class="erp-modal__head"><h2 id="entry-cancel-title">Excluir lançamentos</h2><button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button></div>
        <div class="erp-modal__body erp-stack">
            <p class="erp-muted" data-entry-pick-count></p>
            <div data-form-error aria-live="assertive"></div>
            <div class="erp-field"><label for="entry-cancel-reason">Motivo</label><textarea class="erp-textarea" id="entry-cancel-reason" name="reason" minlength="5" maxlength="255" required placeholder="Ex.: lançado em dobro na importação"></textarea></div>
            <p class="erp-field__hint">Os lançamentos ficam como Cancelado: saem dos relatórios e da cobrança, e o histórico guarda quem fez e o motivo. Conta paga, com boleto no banco ou em mês fechado não pode ser excluída.</p>
        </div>
        <div class="erp-modal__foot"><button type="button" class="erp-btn" data-erp-modal-close>Voltar</button><button type="submit" class="erp-btn erp-btn--danger"><?= $icon('trash', 'erp-icon--sm') ?>Excluir</button></div>
    </form>
</dialog>
<?php endif; ?>
