<?php
/**
 * Cadastro/edição de condomínio (AP-004, ADR 0011).
 * @var string $formTitle @var string $action @var string $cancel
 * @var array<string, mixed> $values @var array<string, string> $errors
 * @var list<string> $states @var array<string, string> $statuses
 * Estrutura semântica mínima; o visual definitivo é da frente claude-layout.
 */
$this->layout('default');
$v = static fn(string $key): string => (string)($values[$key] ?? '');
$field = function (string $key, string $label, string $type = 'text', array $attributes = []) use ($v, $errors): string {
    $id = 'condominium-' . str_replace('_', '-', $key);
    $extra = '';
    foreach ($attributes as $name => $value) {
        $extra .= $value === true ? " {$name}" : " {$name}=\"" . $this->e((string)$value) . '"';
    }
    $error = $errors[$key] ?? null;
    $html = '<div class="erp-field' . ($error ? ' erp-field-invalid' : '') . '">';
    $html .= '<label for="' . $id . '">' . $this->e($label) . (isset($attributes['required']) ? ' <span aria-hidden="true">*</span>' : '') . '</label>';
    $html .= '<input id="' . $id . '" name="' . $key . '" type="' . $type . '" value="' . $this->e($v($key)) . '"' . $extra
        . ($error ? ' aria-invalid="true" aria-describedby="' . $id . '-error"' : '') . '>';
    if ($error) {
        $html .= '<p class="erp-field-error" id="' . $id . '-error">' . $this->e($error) . '</p>';
    }
    return $html . '</div>';
};
?>
<header class="erp-page-header">
    <h1><?= $this->e($formTitle) ?></h1>
    <p>Campos com * são obrigatórios.</p>
</header>

<?php if (!empty($errors)): ?>
    <div class="erp-state erp-state-error" role="alert">
        <p><?= $this->e($errors['_form'] ?? 'Revise os campos destacados.') ?></p>
    </div>
<?php endif; ?>

<form class="erp-form" method="post" action="<?= $this->e($action) ?>" novalidate>
    <?= csrf_input() ?>

    <fieldset>
        <legend>Identificação</legend>
        <?= $field('name', 'Nome', 'text', ['required' => true, 'maxlength' => 160]) ?>
        <?= $field('legal_name', 'Razão social', 'text', ['required' => true, 'maxlength' => 160]) ?>
        <?= $field('document', 'CNPJ', 'text', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 18, 'placeholder' => '00.000.000/0000-00']) ?>
        <div class="erp-field<?= isset($errors['status']) ? ' erp-field-invalid' : '' ?>">
            <label for="condominium-status">Situação <span aria-hidden="true">*</span></label>
            <select id="condominium-status" name="status" required>
                <?php foreach ($statuses as $key => $label): ?>
                    <option value="<?= $this->e($key) ?>"<?= $v('status') === $key ? ' selected' : '' ?>><?= $this->e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </fieldset>

    <fieldset>
        <legend>Endereço</legend>
        <?= $field('zip_code', 'CEP', 'text', ['required' => true, 'inputmode' => 'numeric', 'maxlength' => 9, 'placeholder' => '00000-000']) ?>
        <?= $field('street', 'Logradouro', 'text', ['required' => true, 'maxlength' => 160]) ?>
        <?= $field('number', 'Número', 'text', ['required' => true, 'maxlength' => 20]) ?>
        <?= $field('complement', 'Complemento', 'text', ['maxlength' => 80]) ?>
        <?= $field('district', 'Bairro', 'text', ['required' => true, 'maxlength' => 100]) ?>
        <?= $field('city', 'Cidade', 'text', ['required' => true, 'maxlength' => 100]) ?>
        <div class="erp-field<?= isset($errors['state']) ? ' erp-field-invalid' : '' ?>">
            <label for="condominium-state">UF <span aria-hidden="true">*</span></label>
            <select id="condominium-state" name="state" required<?= isset($errors['state']) ? ' aria-invalid="true" aria-describedby="condominium-state-error"' : '' ?>>
                <option value="">Selecione</option>
                <?php foreach ($states as $state): ?>
                    <option value="<?= $state ?>"<?= strtoupper($v('state')) === $state ? ' selected' : '' ?>><?= $state ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (isset($errors['state'])): ?><p class="erp-field-error" id="condominium-state-error"><?= $this->e($errors['state']) ?></p><?php endif; ?>
        </div>
    </fieldset>

    <fieldset>
        <legend>Contato e estrutura</legend>
        <?= $field('phone', 'Telefone', 'tel', ['maxlength' => 20, 'placeholder' => '(00) 00000-0000']) ?>
        <?= $field('email', 'E-mail', 'email', ['maxlength' => 190]) ?>
        <?= $field('blocks_count', 'Quantidade de blocos', 'number', ['min' => 0, 'max' => 999]) ?>
        <?= $field('units_count', 'Quantidade de unidades', 'number', ['min' => 0, 'max' => 100000]) ?>
    </fieldset>

    <fieldset>
        <legend>Contrato de administração</legend>
        <?= $field('contract_start_date', 'Início do contrato', 'date') ?>
        <?= $field('fee_due_day', 'Dia de vencimento da taxa (1 a 28)', 'number', ['min' => 1, 'max' => 28]) ?>
        <?php if ($v('manager_person_id') !== ''): ?>
            <input type="hidden" name="manager_person_id" value="<?= $this->e($v('manager_person_id')) ?>">
        <?php endif; ?>
        <p class="erp-field-hint">O síndico será escolhido no cadastro de Pessoas (Fase 2).</p>
    </fieldset>

    <fieldset>
        <legend>Conta bancária do condomínio</legend>
        <?= $field('bank_code', 'Banco (código)', 'text', ['inputmode' => 'numeric', 'maxlength' => 3, 'placeholder' => '000']) ?>
        <?= $field('bank_branch', 'Agência', 'text', ['maxlength' => 10]) ?>
        <?= $field('bank_account', 'Conta', 'text', ['maxlength' => 20]) ?>
    </fieldset>

    <div class="erp-form-actions">
        <button type="submit" class="erp-button">Salvar</button>
        <a href="<?= $this->e($cancel) ?>">Cancelar</a>
    </div>
</form>
