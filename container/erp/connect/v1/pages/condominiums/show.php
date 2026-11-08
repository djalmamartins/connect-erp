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
// Cadastros ligados ao condomínio: cada link abre a lista real já filtrada por ele.
$related = [];
foreach (\Source\Services\Erp\Registry\Definitions::all() as $definition) {
    if ($definition['scope'] === 'none' || !$user->can($definition['permission'] . '.view')) {
        continue;
    }
    $related[] = $definition;
}
?>
<div class="erp-grid erp-grid--main">
    <section class="erp-card" aria-labelledby="condo-data-title">
        <div class="erp-card__head"><div><h2 id="condo-data-title">Dados</h2><p>Cadastro do condomínio (AP-004)</p></div></div>
        <dl class="erp-detail-grid">
            <div><dt>Nome</dt><dd><?= $this->e($condominium['name']) ?></dd></div>
            <div><dt>CNPJ</dt><dd><?= $this->e(format_cnpj($condominium['document'])) ?></dd></div>
            <div><dt>Situação</dt><dd><span class="erp-badge erp-badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Ativo' : 'Inativo' ?></span></dd></div>
            <div><dt>Código interno</dt><dd><?= (int)$condominium['id'] ?></dd></div>
        </dl>
    </section>

    <aside class="erp-card" aria-labelledby="condo-related-title">
        <div class="erp-card__head"><div><h2 id="condo-related-title">Cadastros do condomínio</h2><p>Abre a lista já filtrada por este condomínio</p></div></div>
        <?php if ($related === []): ?>
            <p class="erp-field__hint">Você não tem acesso a nenhum cadastro deste condomínio.</p>
        <?php else: ?>
            <ul class="erp-list">
                <?php foreach ($related as $definition): ?>
                    <li><span class="erp-list__icon"><?= $icon('clipboard', 'erp-icon--sm') ?></span>
                        <div class="erp-list__body"><a href="<?= erp_url($definition['route']) ?>?condominio=<?= (int)$condominium['id'] ?>"><strong><?= $this->e($definition['label']) ?></strong></a></div></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </aside>
</div>
