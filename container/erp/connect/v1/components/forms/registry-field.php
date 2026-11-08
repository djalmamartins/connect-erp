<?php
/**
 * Campo de um cadastro real (Definitions + RegistryValidator). Valor e erro vêm do servidor.
 * @var array $field @var mixed $value @var ?string $error @var list<array> $condominiums @var bool $readonly
 */
$id = 'f-' . $field['name'];
$name = $field['name'];
$required = $field['required'] ? ' required' : '';
$disabled = $readonly ? ' disabled' : '';
$invalid = $error !== null ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '';
$star = $field['required'] ? ' <span aria-hidden="true" style="color:var(--erp-danger)">*</span>' : '';
$text = $value === null || is_array($value) || is_bool($value) ? '' : (string)$value;
// Valores gravados chegam normalizados; mostra-os no formato de digitação.
$display = match ($field['type']) {
    'cpf', 'cnpj', 'document' => \Source\Support\Erp\BrDocument::format($text),
    'id_document' => strlen($text) === 11 && ctype_digit($text) ? \Source\Support\Erp\BrDocument::format($text) : $text,
    'money' => is_int($value) ? number_format($value / 100, 2, ',', '.') : $text,
    'percent' => is_float($value) ? number_format($value, 2, ',', '') : $text,
    'phone' => \Source\Support\Erp\RegistryFormat::phone($text),
    default => $text,
};
?>
<div class="erp-field erp-col-<?= (int)$field['span'] ?>">
    <?php if ($field['type'] === 'bool'): ?>
        <span class="erp-field__label" aria-hidden="true">&nbsp;</span>
        <input type="hidden" name="<?= $this->e($name) ?>" value="0"<?= $disabled ?>>
        <label class="erp-check" for="<?= $id ?>"><input type="checkbox" id="<?= $id ?>" name="<?= $this->e($name) ?>" value="1"<?= filter_var($value, FILTER_VALIDATE_BOOL) ? ' checked' : '' ?><?= $disabled ?>><?= $this->e($field['label']) ?></label>
    <?php else: ?>
        <label for="<?= $id ?>"><?= $this->e($field['label']) ?><?= $star ?></label>
        <?php if ($field['type'] === 'select' || $field['type'] === 'condominium'):
            $options = $field['type'] === 'condominium'
                ? array_column($condominiums, 'name', 'id')
                : $field['options']; ?>
            <select class="erp-select" id="<?= $id ?>" name="<?= $this->e($name) ?>"<?= $required . $disabled . $invalid ?>>
                <option value=""><?= $field['type'] === 'condominium' && !$field['required'] ? 'Todos os condomínios' : 'Selecione' ?></option>
                <?php foreach ($options as $key => $label): ?>
                    <option value="<?= $this->e((string)$key) ?>"<?= (string)$key === $text ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($field['type'] === 'longtext'): ?>
            <textarea class="erp-textarea" id="<?= $id ?>" name="<?= $this->e($name) ?>" maxlength="<?= (int)($field['max'] ?? 2000) ?>"<?= $required . $disabled . $invalid ?>><?= $this->e($text) ?></textarea>
        <?php else:
            [$type, $extra, $placeholder] = match ($field['type']) {
                'email' => ['email', ' autocomplete="off" maxlength="254"', 'nome@exemplo.com'],
                'phone' => ['tel', ' maxlength="20"', '(19) 99999-0000'],
                'date' => ['date', ' min="1900-01-01" max="2100-12-31"', ''],
                'money' => ['text', ' inputmode="decimal" maxlength="18"', '0,00'],
                'percent' => ['text', ' inputmode="decimal" maxlength="6"', '0,00'],
                'cpf' => ['text', ' inputmode="numeric" maxlength="14"', '000.000.000-00'],
                'cnpj' => ['text', ' inputmode="numeric" maxlength="18"', '00.000.000/0000-00'],
                'document' => ['text', ' inputmode="numeric" maxlength="18"', 'CPF ou CNPJ'],
                'id_document' => ['text', ' maxlength="20"', 'CPF ou RG'],
                'plate' => ['text', ' maxlength="8" style="text-transform:uppercase"', 'ABC1D23'],
                'oab' => ['text', ' maxlength="12"', 'SP 123456'],
                default => ['text', ' maxlength="' . (int)($field['max'] ?? 160) . '"', ''],
            }; ?>
            <input class="erp-input" type="<?= $type ?>" id="<?= $id ?>" name="<?= $this->e($name) ?>" value="<?= $this->e($display) ?>"<?= $extra ?><?= $placeholder !== '' ? ' placeholder="' . $this->e($placeholder) . '"' : '' ?><?= $required . $disabled . $invalid ?>>
        <?php endif; ?>
        <?php if (!empty($field['hint'])): ?><span class="erp-field__hint"><?= $this->e($field['hint']) ?></span><?php endif; ?>
        <span class="erp-field__error" id="<?= $id ?>-error" aria-live="polite"><?= $error !== null ? $this->e($error) : '' ?></span>
    <?php endif; ?>
</div>
