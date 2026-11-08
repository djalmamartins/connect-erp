<?php
/**
 * Cadastro/edição de um registro real, com histórico (auditoria) e exclusão confirmada.
 * Sem a permissão manage, mostra os dados somente para leitura.
 * @var array $definition @var ?int $recordId @var array $values @var array<string,string> $errors
 * @var list<array> $history @var list<array> $condominiums @var bool $canManage
 */
$this->layout('default');
$route = $definition['route'];
$isNew = $recordId === null;
$readonly = !$canManage;
$action = erp_url($isNew ? $route : $route . '/' . (int)$recordId);
$actionLabels = ['create' => 'Cadastro criado', 'update' => 'Cadastro alterado', 'delete' => 'Cadastro excluído'];
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ' · ' . $definition['label'],
    'pageTitle' => $title,
    'pageLead' => $isNew ? 'Preencha os dados e salve. Campos com * são obrigatórios.' : ($readonly ? 'Você tem acesso somente para consulta.' : 'Altere os dados e salve. Toda alteração fica registrada no histórico.'),
]);
?>
<?php if ($errors !== []): ?>
    <div class="erp-alert erp-alert--danger" role="alert"><?= $icon('alert') ?><div><strong><?= $this->e($errors['_form'] ?? 'Revise os campos destacados.') ?></strong><?= count(array_diff_key($errors, ['_form' => 1])) > 0 ? count(array_diff_key($errors, ['_form' => 1])) . ' campo(s) com problema.' : '' ?></div></div>
<?php endif; ?>
<?php if ($definition['scope'] !== 'none' && $condominiums === [] && $isNew): ?>
    <div class="erp-alert erp-alert--warning" role="note"><?= $icon('info') ?><div><?php if ($user->can('erp.condominiums.manage')): ?>Nenhum condomínio ativo cadastrado. <a href="<?= erp_url('/condominios/novo') ?>">Cadastre um condomínio</a> antes de continuar.<?php else: ?>Você ainda não tem acesso a nenhum condomínio ativo. Solicite o vínculo ao administrador do sistema.<?php endif; ?></div></div>
<?php endif; ?>

<div class="erp-grid erp-grid--main">
    <form class="erp-card erp-form" method="post" action="<?= $action ?>" data-erp-validate novalidate aria-labelledby="reg-form-title">
        <?= csrf_input() ?>
        <div class="erp-card__head"><div><h2 id="reg-form-title">Dados <?= $definition['gender'] === 'a' ? 'da' : 'do' ?> <?= $this->e($definition['entity']) ?></h2></div></div>
        <div class="erp-form__grid">
            <?php foreach ($definition['fields'] as $name => $field) {
                $this->insert('components/forms/registry-field', [
                    'field' => $field, 'value' => $values[$name] ?? null, 'error' => $errors[$name] ?? null,
                    'condominiums' => $condominiums, 'readonly' => $readonly,
                ]);
            } ?>
            <?php $this->insert('components/forms/registry-field', [
                'field' => ['name' => 'status', 'label' => 'Situação', 'type' => 'select', 'required' => true, 'span' => 4, 'options' => $definition['status'], 'hint' => null],
                'value' => $values['status'] ?? 'active', 'error' => $errors['status'] ?? null, 'condominiums' => [], 'readonly' => $readonly,
            ]); ?>
        </div>
        <div class="erp-form__actions">
            <a class="erp-btn" href="<?= erp_url($route) ?>"><?= $readonly ? 'Voltar' : 'Cancelar' ?></a>
            <?php if (!$readonly): ?><button type="submit" class="erp-btn erp-btn--primary">Salvar</button><?php endif; ?>
        </div>
    </form>

    <aside class="erp-card" aria-labelledby="reg-history-title">
        <div class="erp-card__head"><div><h2 id="reg-history-title">Histórico</h2><p>Registro de auditoria deste cadastro</p></div></div>
        <?php if ($isNew || $history === []): ?>
            <p class="erp-field__hint"><?= $isNew ? 'O histórico começa quando o cadastro for salvo.' : 'Sem alterações registradas.' ?></p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($history as $entry): ?>
                    <li><span class="erp-list__icon<?= $entry['action'] === 'delete' ? ' erp-list__icon--danger' : '' ?>"><?= $icon($entry['action'] === 'create' ? 'plus' : 'edit', 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><strong><?= $this->e($actionLabels[$entry['action']] ?? $entry['action']) ?></strong>
                            <small><?= $this->e($entry['user'] ?? 'Sistema') ?><?= $entry['action'] === 'update' && $entry['fields'] !== [] ? ' · ' . $this->e(implode(', ', $entry['fields'])) : '' ?></small></div>
                        <span class="erp-list__meta"><?= $this->e(date_fmt($entry['created_at'], 'd/m/Y H:i')) ?></span></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!$isNew && !$readonly): ?>
            <div style="margin-top:16px"><button type="button" class="erp-btn erp-btn--danger erp-btn--block" data-erp-modal-open="reg-delete"><?= $icon('trash', 'erp-icon--sm') ?>Excluir <?= $this->e($definition['entity']) ?></button></div>
        <?php endif; ?>
    </aside>
</div>

<?php if (!$isNew && !$readonly): ?>
    <dialog class="erp-modal" id="reg-delete" aria-labelledby="reg-delete-title">
        <form method="post" action="<?= erp_url($route . '/' . (int)$recordId . '/excluir') ?>">
            <?= csrf_input() ?>
            <div class="erp-modal__head">
                <h2 id="reg-delete-title">Excluir <?= $this->e($definition['entity']) ?></h2>
                <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
            </div>
            <div class="erp-modal__body">
                <div class="erp-alert erp-alert--danger"><?= $icon('alert') ?><div><strong>O registro sai das listagens e da API.</strong>O histórico de auditoria é mantido. Se a pessoa ainda tiver vínculo, prefira mudar a situação para inativo.</div></div>
            </div>
            <div class="erp-modal__foot">
                <button type="button" class="erp-btn" data-erp-modal-close>Cancelar</button>
                <button type="submit" class="erp-btn erp-btn--danger">Excluir</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>
