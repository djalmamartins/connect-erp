<?php
/**
 * Configuração das unidades em massa (revisão de 15/11, etapa 2, ADR 0311). Modal da lista de unidades (AP-006):
 * cada campo começa em "Manter como está" e só os campos alterados vão para POST /api/v1/units/bulk-settings,
 * que valida tudo de novo pela regra do cadastro. As imobiliárias vêm do GET do mesmo endereço.
 * @var array $definition @var int $bulkCondominium
 */
use Source\Services\Erp\UnitBulkSettings;

$fields = $definition['fields'];
$keep = '<option value="" selected>Manter como está</option>';
?>
<dialog class="erp-modal erp-modal--wide" id="unit-bulk-settings" aria-labelledby="unit-bulk-settings-title" data-erp-unit-bulk="<?= $this->e(url('/api/v1/units/bulk-settings')) ?>" data-condominium="<?= (int)$bulkCondominium ?>">
    <form method="dialog" novalidate data-unit-bulk-form>
        <?= csrf_input() ?>
        <div class="erp-modal__head">
            <h2 id="unit-bulk-settings-title">Configurar unidades em massa</h2>
            <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
        </div>
        <div class="erp-modal__body">
            <p class="erp-muted" data-unit-bulk-count>Nenhuma unidade marcada.</p>
            <p class="erp-muted">Só muda o que você escolher. O que ficar em <strong>Manter como está</strong> continua como está em cada unidade.</p>
            <div data-form-error></div>
            <div class="erp-grid erp-grid--3 erp-unit-bulk__grid">
                <?php foreach (UnitBulkSettings::SETTINGS as $name): $field = $fields[$name]; $id = 'bulk-' . $name; ?>
                    <div class="erp-field">
                        <label class="erp-field__label" for="<?= $id ?>"><?= $this->e($field['label']) ?></label>
                        <?php if ($field['type'] === 'bool'): ?>
                            <select class="erp-select" id="<?= $id ?>" name="<?= $name ?>"><?= $keep ?><option value="1">Sim</option><option value="0">Não</option></select>
                        <?php elseif ($field['type'] === 'select'): ?>
                            <select class="erp-select" id="<?= $id ?>" name="<?= $name ?>"><?= $keep ?><?php foreach ($field['options'] as $value => $label): ?><option value="<?= $this->e((string)$value) ?>"><?= $this->e($label) ?></option><?php endforeach; ?></select>
                        <?php elseif ($field['type'] === 'money'): ?>
                            <input class="erp-input" id="<?= $id ?>" name="<?= $name ?>" inputmode="decimal" data-erp-money placeholder="Manter como está">
                        <?php else: ?>
                            <div class="erp-inline-field">
                                <input class="erp-input" type="number" id="<?= $id ?>" name="<?= $name ?>" min="<?= (int)($field['min'] ?? 1) ?>" max="<?= (int)($field['max_value'] ?? 31) ?>" placeholder="Manter como está">
                                <label class="erp-check"><input type="checkbox" name="<?= $name ?>__clear" value="1"> Tirar</label>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($field['hint'])): ?><small class="erp-field__hint"><?= $this->e($field['hint']) ?></small><?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <div class="erp-field">
                    <label class="erp-field__label" for="bulk-partner_agency_id">Imobiliária</label>
                    <select class="erp-select" id="bulk-partner_agency_id" name="partner_agency_id" data-unit-bulk-agencies><?= $keep ?><option value="none">Nenhuma (tirar)</option></select>
                    <small class="erp-field__hint">Imobiliárias ativas do cadastro</small>
                </div>
            </div>
        </div>
        <div class="erp-modal__foot">
            <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
            <button type="submit" class="erp-btn erp-btn--primary" data-unit-bulk-save><?= $icon('check', 'erp-icon--sm') ?>Aplicar às unidades marcadas</button>
        </div>
    </form>
</dialog>
