<?php
/**
 * Outras unidades num único cadastro de lançamento, conta a pagar ou a receber (ADR 0313, revisão de 15/11 item 15).
 * Só ao criar. Cada unidade marcada vira um lançamento com os mesmos dados (EntryUnits); as opções mostram só as
 * unidades do condomínio escolhido (erp.js, data-entry-units). O servidor confere unidade e condomínio de novo.
 * @var list<array{id:int, condominium_id:int, label:string}> $units @var array $values @var ?string $error
 */
use Source\Services\Erp\EntryUnits;

$chosen = EntryUnits::extraIds($values['extra_unit_ids'] ?? null, $values['unit_id'] ?? null);
$mode = is_string($values['unit_amount_mode'] ?? null) && isset(EntryUnits::MODES[$values['unit_amount_mode']]) ? $values['unit_amount_mode'] : 'each';
?>
<fieldset class="erp-fieldset erp-col-12 erp-entry-units<?= $error ? ' is-invalid' : '' ?>" id="f-extra_unit_ids" data-entry-units>
    <legend>Lançar também para outras unidades</legend>
    <p class="erp-field__hint">Ex.: controle do portão para as unidades 01 e 303. Escolha a primeira em <strong>Unidade</strong> e marque as outras aqui: sai um lançamento para cada uma, com os mesmos dados.</p>
    <?php if ($units === []): ?>
        <p class="erp-muted">Nenhuma unidade cadastrada.</p>
    <?php else: ?>
        <div class="erp-entry-units__list" role="group" aria-label="Outras unidades">
            <?php foreach ($units as $unit): ?>
                <label class="erp-check" data-condominium="<?= (int)$unit['condominium_id'] ?>"><input type="checkbox" name="extra_unit_ids[]" value="<?= (int)$unit['id'] ?>"<?= in_array((int)$unit['id'], $chosen, true) ? ' checked' : '' ?>> <?= $this->e($unit['label']) ?></label>
            <?php endforeach; ?>
        </div>
        <p class="erp-muted" data-entry-units-empty hidden>Escolha o condomínio para ver as unidades.</p>
        <div class="erp-entry-units__mode" role="radiogroup" aria-label="Valor com várias unidades">
            <?php foreach (EntryUnits::MODES as $value => $label): ?>
                <label class="erp-check"><input type="radio" name="unit_amount_mode" value="<?= $value ?>"<?= $value === $mode ? ' checked' : '' ?>> <?= $this->e($label) ?></label>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?><span class="erp-field__error" role="alert"><?= $this->e($error) ?></span><?php endif; ?>
</fieldset>
