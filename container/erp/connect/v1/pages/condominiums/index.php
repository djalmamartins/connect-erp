<?php
/**
 * Condomínios (AP-004) com dados reais, somente leitura (CondominiumScope).
 * @var list<array{id:int,name:string,document:?string,status:string}> $condominiums
 */
$this->layout('default');
ob_start(); ?>
    <button type="button" class="erp-btn erp-btn--primary" data-erp-pending="Cadastro pendente: os campos do condomínio aguardam a confirmação da ficha AP-004. Nada foi criado."><?= $icon('plus', 'erp-icon--sm') ?>Novo condomínio</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínios',
    'pageTitle' => 'Condomínios',
    'pageLead' => 'Condomínios administrados pela Connect aos quais você tem acesso.',
    'pageActions' => $actions,
]);
?>
<div class="erp-alert erp-alert--success" role="note"><?= $icon('shield') ?><div><strong>Dados reais, somente leitura</strong>A lista respeita o seu escopo de acesso. Cadastro e edição serão liberados com a ficha AP-004.</div></div>

<?php if ($condominiums === []): ?>
    <section class="erp-card">
        <div class="erp-empty" role="status">
            <span class="erp-empty__icon"><?= $icon('building') ?></span>
            <h2>Nenhum condomínio disponível</h2>
            <p>Você ainda não tem acesso a nenhum condomínio ativo. Solicite o vínculo ao administrador do sistema.</p>
        </div>
    </section>
<?php else: ?>
    <section data-erp-list aria-label="Condomínios">
        <form class="erp-filters" role="search" onsubmit="return false">
            <div class="erp-field erp-field--grow"><label for="condo-q">Pesquisar</label><input class="erp-input" type="search" id="condo-q" placeholder="Nome ou CNPJ" data-erp-table-filter></div>
            <div class="erp-field"><label for="condo-status">Situação</label><select class="erp-select" id="condo-status" data-erp-select-filter><option value="">Todas</option><option>Ativo</option><option>Inativo</option></select></div>
        </form>
        <div class="erp-card erp-card--flush" style="margin-top:16px">
            <div class="erp-card__head"><div><h2>Condomínios acessíveis</h2><p><?= count($condominiums) ?> no seu escopo</p></div></div>
            <div class="erp-table-wrap" data-erp-table data-per-page="10" role="region" aria-label="Condomínios acessíveis" tabindex="0">
                <table class="erp-table">
                    <caption class="erp-sr-only">Condomínios acessíveis (<?= count($condominiums) ?>)</caption>
                    <thead>
                    <tr>
                        <th scope="col"><button type="button" class="erp-sort" data-erp-sort="0">Nome</button></th>
                        <th scope="col"><button type="button" class="erp-sort" data-erp-sort="1">CNPJ</button></th>
                        <th scope="col"><button type="button" class="erp-sort" data-erp-sort="2">Situação</button></th>
                        <th scope="col" class="is-num"><span class="erp-sr-only">Ações</span></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($condominiums as $condominium): $active = $condominium['status'] === 'active'; ?>
                        <tr>
                            <td><a href="<?= erp_url('/condominios/' . (int)$condominium['id']) ?>"><strong><?= $this->e($condominium['name']) ?></strong></a></td>
                            <td><?= $this->e(format_cnpj($condominium['document'])) ?></td>
                            <td><span class="erp-badge erp-badge--<?= $active ? 'success' : 'danger' ?>"><?= $active ? 'Ativo' : 'Inativo' ?></span></td>
                            <td class="is-num"><a class="erp-btn erp-btn--ghost erp-btn--sm" href="<?= erp_url('/condominios/' . (int)$condominium['id']) ?>" aria-label="Ver <?= $this->e($condominium['name']) ?>"><?= $icon('eye', 'erp-icon--sm') ?></a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="erp-pagination" data-erp-pager><span data-erp-pager-info></span><div class="erp-pagination__pages" data-erp-pager-pages></div></div>
        </div>
    </section>
<?php endif; ?>
