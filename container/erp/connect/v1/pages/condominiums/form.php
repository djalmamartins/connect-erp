<?php
/**
 * Cadastro/edição de condomínio (AP-004, ADR 0011) na moldura do ERP.
 * @var string $formTitle @var string $action @var string $cancel
 * @var array<string, mixed> $values @var array<string, string> $errors
 * @var list<string> $states @var array<string, string> $statuses
 */
$this->layout('default');
$v = static fn(string $key): string => (string)($values[$key] ?? '');
$star = ' <span aria-hidden="true" style="color:var(--erp-danger)">*</span>';
$field = function (string $key, string $label, int $span, string $type = 'text', array $attributes = [], ?string $hint = null) use ($v, $errors, $star): string {
    $id = 'condominium-' . str_replace('_', '-', $key);
    $extra = '';
    foreach ($attributes as $name => $value) {
        $extra .= $value === true ? " {$name}" : " {$name}=\"" . $this->e((string)$value) . '"';
    }
    $error = $errors[$key] ?? null;
    $html = '<div class="erp-field erp-col-' . $span . '">';
    $html .= '<label for="' . $id . '">' . $this->e($label) . (isset($attributes['required']) ? $star : '') . '</label>';
    $html .= '<input class="erp-input" id="' . $id . '" name="' . $key . '" type="' . $type . '" value="' . $this->e($v($key)) . '"' . $extra
        . ($error ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '') . '>';
    if ($hint !== null) {
        $html .= '<span class="erp-field__hint">' . $this->e($hint) . '</span>';
    }
    $html .= '<span class="erp-field__error" id="' . $id . '-error" aria-live="polite">' . ($error ? $this->e($error) : '') . '</span>';
    return $html . '</div>';
};
$select = function (string $key, string $label, int $span, array $options, bool $placeholder) use ($v, $errors, $star): string {
    $id = 'condominium-' . str_replace('_', '-', $key);
    $error = $errors[$key] ?? null;
    $html = '<div class="erp-field erp-col-' . $span . '"><label for="' . $id . '">' . $this->e($label) . $star . '</label>';
    $html .= '<select class="erp-select" id="' . $id . '" name="' . $key . '" required' . ($error ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '') . '>';
    if ($placeholder) {
        $html .= '<option value="">Selecione</option>';
    }
    foreach ($options as $value => $text) {
        $html .= '<option value="' . $this->e((string)$value) . '"' . (strtoupper($v($key)) === strtoupper((string)$value) ? ' selected' : '') . '>' . $this->e($text) . '</option>';
    }
    $html .= '</select><span class="erp-field__error" id="' . $id . '-error" aria-live="polite">' . ($error ? $this->e($error) : '') . '</span></div>';
    return $html;
};
$isNew = str_ends_with($action, '/novo');
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínios',
    'pageTitle' => $formTitle,
    'pageLead' => $isNew
        ? 'Preencha os dados e salve. Campos com * são obrigatórios. Ao salvar, o checklist de chegada é aberto.'
        : 'Altere os dados e salve. Campos com * são obrigatórios.',
]);
?>
<?php if (!empty($errors)): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($errors['_form'] ?? 'Revise os campos destacados.') ?></strong><?= count(array_diff_key($errors, ['_form' => 1])) > 0 ? count(array_diff_key($errors, ['_form' => 1])) . ' campo(s) com problema.' : '' ?></div></div>
<?php endif; ?>

<form class="erp-card erp-form" method="post" action="<?= $this->e($action) ?>" data-erp-validate novalidate aria-labelledby="condominium-form-title">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="condominium-form-title">Dados do condomínio</h2></div></div>

    <fieldset class="erp-fieldset">
        <legend>Identificação</legend>
        <div class="erp-form__grid">
            <?= $field('name', 'Nome', 6, 'text', ['required' => true, 'maxlength' => 160]) ?>
            <?= $field('legal_name', 'Razão social', 6, 'text', ['required' => true, 'maxlength' => 160]) ?>
            <?= $field('document', 'CNPJ', 4, 'text', ['inputmode' => 'numeric', 'maxlength' => 18, 'placeholder' => '00.000.000/0000-00'], 'Opcional: deixe em branco se o condomínio não tiver CNPJ.') ?>
            <?= $select('status', 'Situação', 4, $statuses, false) ?>
        </div>
    </fieldset>

    <fieldset class="erp-fieldset">
        <legend>Endereço</legend>
        <div class="erp-form__grid">
            <?= $field('zip_code', 'CEP', 3, 'text', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 9, 'placeholder' => '00000-000', 'autocomplete' => 'postal-code'], 'O endereço é preenchido pelo CEP.') ?>
            <?= $field('street', 'Logradouro', 6, 'text', ['required' => true, 'maxlength' => 160]) ?>
            <?= $field('number', 'Número', 3, 'text', ['required' => true, 'maxlength' => 20]) ?>
            <?= $field('complement', 'Complemento', 3, 'text', ['maxlength' => 80]) ?>
            <?= $field('district', 'Bairro', 3, 'text', ['required' => true, 'maxlength' => 100]) ?>
            <?= $field('city', 'Cidade', 3, 'text', ['required' => true, 'maxlength' => 100]) ?>
            <?= $select('state', 'UF', 3, array_combine($states, $states), true) ?>
        </div>
    </fieldset>

    <fieldset class="erp-fieldset">
        <legend>Contato e estrutura</legend>
        <div class="erp-form__grid">
            <?= $field('phone', 'Telefone', 3, 'tel', ['maxlength' => 20, 'placeholder' => '(00) 00000-0000']) ?>
            <?= $field('email', 'E-mail', 3, 'email', ['maxlength' => 190, 'placeholder' => 'nome@exemplo.com']) ?>
            <?= $field('blocks_count', 'Blocos', 3, 'number', ['min' => 0, 'max' => 999, 'step' => 1]) ?>
            <?= $field('units_count', 'Unidades', 3, 'number', ['min' => 0, 'max' => 100000, 'step' => 1]) ?>
        </div>
    </fieldset>

    <fieldset class="erp-fieldset">
        <legend>Contrato de administração</legend>
        <div class="erp-form__grid">
            <?= $field('contract_start_date', 'Início do contrato', 4, 'date', ['min' => '1900-01-01', 'max' => '2100-12-31']) ?>
            <?= $field('fee_due_day', 'Dia de vencimento da taxa', 4, 'number', ['min' => 1, 'max' => 28, 'step' => 1], 'De 1 a 28.') ?>
            <div class="erp-field erp-col-4"><span class="erp-field__label" aria-hidden="true">&nbsp;</span><span class="erp-field__hint">O síndico é cadastrado em <a href="<?= erp_url('/cadastros/sindico-e-conselho') ?>">Síndico e conselho</a>.</span></div>
        </div>
    </fieldset>

    <fieldset class="erp-fieldset">
        <legend>Conta bancária do condomínio</legend>
        <div class="erp-form__grid">
            <?= $field('bank_code', 'Banco (código)', 4, 'text', ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '000']) ?>
            <?= $field('bank_branch', 'Agência', 4, 'text', ['maxlength' => 10]) ?>
            <?= $field('bank_account', 'Conta', 4, 'text', ['maxlength' => 20]) ?>
        </div>
    </fieldset>

    <div class="erp-form__actions">
        <a class="erp-btn" href="<?= $this->e($cancel) ?>">Cancelar</a>
        <button type="submit" class="erp-btn erp-btn--primary">Salvar</button>
    </div>
</form>
