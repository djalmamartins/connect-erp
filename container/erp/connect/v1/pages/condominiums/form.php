<?php
/**
 * Cadastro/edição de condomínio (AP-004), gravado por CondominiumService.
 * @var ?int $recordId @var array $values @var array<string,string> $errors @var array<string,string> $statuses
 */
$this->layout('default');
$isNew = $recordId === null;
$action = erp_url($isNew ? '/condominios' : '/condominios/' . (int)$recordId);
$back = erp_url($isNew ? '/condominios' : '/condominios/' . (int)$recordId);
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínios',
    'pageTitle' => $title,
    'pageLead' => $isNew ? 'Preencha os dados e salve. Campos com * são obrigatórios.' : 'Altere os dados e salve. Toda alteração fica registrada na auditoria.',
]);
$fields = [
    ['name' => 'name', 'label' => 'Nome do condomínio', 'type' => 'text', 'required' => true, 'span' => 8, 'max' => 160, 'hint' => null],
    ['name' => 'document', 'label' => 'CNPJ', 'type' => 'cnpj', 'required' => false, 'span' => 4, 'hint' => 'Opcional. Não pode repetir outro condomínio.'],
    ['name' => 'status', 'label' => 'Situação', 'type' => 'select', 'required' => true, 'span' => 4, 'options' => $statuses, 'hint' => 'Condomínio inativo some das telas e dos cadastros; nada é apagado.'],
];
?>
<?php if ($errors !== []): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($errors['_form'] ?? 'Revise os campos destacados.') ?></strong><?= count(array_diff_key($errors, ['_form' => 1])) > 0 ? count(array_diff_key($errors, ['_form' => 1])) . ' campo(s) com problema.' : '' ?></div></div>
<?php endif; ?>

<form class="erp-card erp-form" method="post" action="<?= $action ?>" data-erp-validate novalidate aria-labelledby="condo-form-title">
    <?= csrf_input() ?>
    <div class="erp-card__head"><div><h2 id="condo-form-title">Dados do condomínio</h2></div></div>
    <div class="erp-form__grid">
        <?php foreach ($fields as $field) {
            $this->insert('components/forms/registry-field', [
                'field' => $field, 'value' => $values[$field['name']] ?? ($field['name'] === 'status' ? 'active' : null),
                'error' => $errors[$field['name']] ?? null, 'condominiums' => [], 'readonly' => false,
            ]);
        } ?>
    </div>
    <div class="erp-form__actions">
        <a class="erp-btn" href="<?= $back ?>">Cancelar</a>
        <button type="submit" class="erp-btn erp-btn--primary">Salvar</button>
    </div>
</form>
