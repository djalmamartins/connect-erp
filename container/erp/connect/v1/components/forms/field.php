<?php
/**
 * Campo de formulário a partir de uma definição FieldSpec::parse().
 * @var array{label: string, type: string, options: list<string>, required: bool, span: int, id: string} $field
 * @var array $vocabulary
 */
$id = $field['id'];
$req = $field['required'] ? ' required' : '';
$star = $field['required'] ? ' <span aria-hidden="true" style="color:var(--erp-danger)">*</span>' : '';
$options = match ($field['type']) {
    'condo' => $vocabulary['condos'] ?? [],
    'bank' => $vocabulary['banks'] ?? [],
    default => $field['options'],
};
?>
<div class="erp-field erp-col-<?= (int)$field['span'] ?>">
    <?php if ($field['type'] === 'bool'): ?>
        <span class="erp-field__label" aria-hidden="true">&nbsp;</span>
        <label class="erp-check" for="<?= $this->e($id) ?>"><input type="checkbox" id="<?= $this->e($id) ?>" name="<?= $this->e($id) ?>"><?= $this->e($field['label']) ?></label>
    <?php else: ?>
        <label for="<?= $this->e($id) ?>"><?= $this->e($field['label']) ?><?= $star ?></label>
        <?php if (in_array($field['type'], ['select', 'condo', 'bank'], true)): ?>
            <select class="erp-select" id="<?= $this->e($id) ?>" name="<?= $this->e($id) ?>"<?= $req ?>>
                <option value="">Selecione</option>
                <?php foreach ($options as $option): ?><option><?= $this->e($option) ?></option><?php endforeach; ?>
            </select>
        <?php elseif ($field['type'] === 'longtext'): ?>
            <textarea class="erp-textarea" id="<?= $this->e($id) ?>" name="<?= $this->e($id) ?>" maxlength="4000"<?= $req ?>></textarea>
        <?php elseif ($field['type'] === 'file'): ?>
            <input class="erp-input" type="file" id="<?= $this->e($id) ?>" name="<?= $this->e($id) ?>" accept=".pdf,.jpg,.jpeg,.png"<?= $req ?>>
            <span class="erp-field__hint">PDF, JPG ou PNG. O envio será habilitado com o armazenamento do módulo.</span>
        <?php else:
            [$inputType, $extra, $placeholder] = match ($field['type']) {
                'email' => ['email', ' autocomplete="email" maxlength="254"', 'nome@exemplo.com'],
                'phone' => ['tel', ' autocomplete="tel" pattern="[0-9()+\s-]{8,20}"', '(19) 99999-0000'],
                'date' => ['date', '', ''],
                'month' => ['month', '', ''],
                'int' => ['number', ' min="0" step="1" inputmode="numeric"', ''],
                'pct' => ['number', ' min="0" max="100" step="0.01" inputmode="decimal"', '0,00'],
                'money' => ['text', ' inputmode="decimal" pattern="\d{1,3}(\.?\d{3})*(,\d{2})?"', '0,00'],
                'doc' => ['text', ' inputmode="numeric" pattern="[0-9./-]{11,18}" maxlength="18"', 'Somente números'],
                default => ['text', ' maxlength="180"', ''],
            }; ?>
            <input class="erp-input" type="<?= $inputType ?>" id="<?= $this->e($id) ?>" name="<?= $this->e($id) ?>"<?= $extra ?><?= $placeholder !== '' ? ' placeholder="' . $this->e($placeholder) . '"' : '' ?><?= $req ?>>
            <?php if ($field['type'] === 'money'): ?><span class="erp-field__hint">Em reais, ex.: 1.250,00</span><?php endif; ?>
        <?php endif; ?>
        <span class="erp-field__error" aria-live="polite"></span>
    <?php endif; ?>
</div>
