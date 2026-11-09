<?php
/**
 * Emissão do recibo (AP-040, ADR 0057 e 0073): escolher a unidade (GET ?unidade=) e marcar as contas
 * pagas sem recibo válido. O formulário de emissão é o dos documentos da unidade
 * (data-unit-document-form), com entry_ids; o servidor confere de novo cada conta na emissão.
 * @var list<array> $documentUnits @var ?int $receiptUnit @var list<array> $receiptEntries @var string $route
 * @var callable $brl @var callable $date @var callable $month
 */
$unitLabel = null;
foreach ($documentUnits as $u) {
    if ((int)$u['id'] === $receiptUnit) {
        $unitLabel = $u['label'];
    }
}
?>
<form class="erp-unit-pick erp-unit-pick--inline" method="get" action="<?= $this->e($route) ?>" role="search" aria-label="Escolher a unidade do recibo" data-erp-loading>
    <label for="receipt-unit">Unidade</label>
    <select class="erp-select" id="receipt-unit" name="unidade" data-erp-autosubmit required><option value="">Selecione</option><?php $group = null; foreach ($documentUnits as $u): if ($group !== $u['condominium_name']): if ($group !== null): ?></optgroup><?php endif; $group = $u['condominium_name']; ?><optgroup label="<?= $this->e($group) ?>"><?php endif; ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === $receiptUnit ? ' selected' : '' ?>><?= $this->e($u['label']) ?></option><?php endforeach; ?></optgroup></select>
    <button type="submit" class="erp-btn">Ver contas pagas</button>
</form>
<?php if ($receiptUnit === null): ?>
    <p class="erp-field__hint">Escolha a unidade para ver as contas pagas que ainda não têm recibo.</p>
<?php elseif ($receiptEntries === []): ?>
    <div class="erp-empty"><span class="erp-empty__icon"><?= $icon('receipt') ?></span><strong>Nenhuma conta paga sem recibo</strong><p>A unidade <?= $this->e((string)$unitLabel) ?> não tem conta a receber paga fora de um recibo válido.</p></div>
<?php else: ?>
    <form data-unit-document-form data-receipt-form novalidate>
        <input type="hidden" name="unit_id" value="<?= (int)$receiptUnit ?>">
        <fieldset class="erp-receipt-entries" data-receipt-entries>
            <legend>Contas pagas da unidade <?= $this->e((string)$unitLabel) ?> <span class="erp-muted">(marque de 1 a 60)</span></legend>
            <label class="erp-check erp-receipt-entries__all"><input type="checkbox" data-receipt-all> Marcar todas</label>
            <?php foreach ($receiptEntries as $e): ?>
                <label class="erp-check erp-receipt-entry"><input type="checkbox" name="entry_ids" value="<?= (int)$e['id'] ?>" data-cents="<?= (int)$e['amount_cents'] ?>"><span><strong><?= $this->e($e['description']) ?></strong><small class="erp-muted">comp. <?= $this->e($month($e['competence'])) ?> · pago em <?= $this->e($date($e['paid_on'])) ?></small></span><span class="erp-receipt-entry__value"><?= $this->e($brl((int)$e['amount_cents'])) ?></span></label>
            <?php endforeach; ?>
        </fieldset>
        <p class="erp-receipt-total" aria-live="polite" data-receipt-total>Nenhuma conta marcada.</p>
        <div class="erp-field"><label for="unit-doc-asof">Data do recibo</label><input class="erp-input" type="date" id="unit-doc-asof" name="as_of" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required></div>
        <div data-form-error aria-live="assertive"></div>
        <div class="erp-form__actions"><button type="submit" class="erp-btn erp-btn--primary"><?= $icon('file', 'erp-icon--sm') ?>Emitir recibo</button></div>
    </form>
<?php endif; ?>
