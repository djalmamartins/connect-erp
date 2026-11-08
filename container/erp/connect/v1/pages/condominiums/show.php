<?php
/**
 * Detalhe de um condomínio (dados reais, somente leitura).
 * @var array{id:int,name:string,document:?string,status:string} $condominium
 */
$this->layout('default');
$active = $condominium['status'] === 'active';
ob_start(); ?>
    <a class="erp-btn" href="<?= erp_url('/condominios') ?>"><?= $icon('chevron-left', 'erp-icon--sm') ?>Voltar</a>
    <a class="erp-btn" href="<?= erp_url('/condominios/' . (int)$condominium['id'] . '/checklist') ?>"><?= $icon('clipboard', 'erp-icon--sm') ?>Checklist de chegada</a>
    <?php if ($user->can('erp.condominiums.manage')): ?><a class="erp-btn erp-btn--primary" href="<?= erp_url('/condominios/' . (int)$condominium['id'] . '/editar') ?>"><?= $icon('edit', 'erp-icon--sm') ?>Editar</a><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínio',
    'pageTitle' => $condominium['name'],
    'pageActions' => $actions,
]);
$tabs = ['Dados', 'Unidades', 'Contas bancárias', 'Configurações', 'Vínculos', 'Histórico'];
?>
<section class="erp-card">
    <div class="erp-tabs" role="tablist" aria-label="Seções do condomínio">
        <?php foreach ($tabs as $i => $tab): ?>
            <button type="button" role="tab" id="condo-tab-<?= $i ?>" aria-controls="condo-panel-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i ? ' tabindex="-1"' : '' ?>><?= $this->e($tab) ?></button>
        <?php endforeach; ?>
    </div>
    <?php foreach ($tabs as $i => $tab): ?>
        <div class="erp-tabpanel" role="tabpanel" id="condo-panel-<?= $i ?>" aria-labelledby="condo-tab-<?= $i ?>"<?= $i ? ' hidden' : '' ?>>
            <?php if ($i === 0): ?>
                <dl class="erp-detail-grid">
                    <div><dt>Nome</dt><dd><?= $this->e($condominium['name']) ?></dd></div>
                    <div><dt>CNPJ</dt><dd><?= $this->e(format_cnpj($condominium['document'])) ?></dd></div>
                    <div><dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Ativo' : 'Inativo' ?></span></dd></div>
                    <div><dt>Código interno</dt><dd><?= (int)$condominium['id'] ?></dd></div>
                </dl>
            <?php else: ?>
                <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'clipboard', 'emptyTitle' => $tab, 'emptyText' => 'Esta seção mostrará ' . mb_strtolower($tab) . ' do condomínio quando o módulo correspondente estiver integrado.']); ?>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</section>
