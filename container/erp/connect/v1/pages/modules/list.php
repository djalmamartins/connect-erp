<?php
/**
 * Página de cadastro/listagem: indicadores, filtros, tabela com ações por linha,
 * modal de cadastro/edição, detalhe com abas e confirmação de exclusão.
 * @var array $currentPage @var array $spec @var list<array> $columns @var list<array> $fields @var array $rows
 */
$this->layout('default');
$tableId = 'lst-' . strtolower($currentPageId);
$entity = $spec['entity'];
$article = ($spec['gender'] ?? 'o') === 'a' ? 'Nova' : 'Novo';
$statusColumn = null;
foreach ($columns as $column) { if ($column['type'] === 'status') { $statusColumn = $column; } }
$canCreate = $fields !== [];
ob_start(); ?>
    <button type="button" class="erp-btn" data-erp-pending="Exportação pendente: será gerada com os dados reais do módulo."><?= $icon('download', 'erp-icon--sm') ?>Exportar</button>
    <?php if ($canCreate): ?><button type="button" class="erp-btn erp-btn--primary" data-erp-modal-open="<?= $tableId ?>-form"><?= $icon('plus', 'erp-icon--sm') ?><?= $this->e($article . ' ' . $entity) ?></button><?php endif; ?>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => $currentPage['note'] ?: null,
    'pageActions' => $actions,
]);
$this->insert('components/notifications/proposal');
?>
<?php if (!empty($spec['kpis']) && $rows !== []): ?>
    <section class="erp-grid erp-grid--3" aria-label="Resumo">
        <?php foreach ($spec['kpis'] as $i => [$label, $value]) { $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => $value, 'meta' => 'Demonstração', 'icon' => ['chart', 'wallet', 'alert'][$i % 3], 'tone' => $i === 2 ? 'warning' : '', 'trend' => null], 'delay' => $i * 60]); } ?>
    </section>
<?php endif; ?>
<section class="erp-list-page" data-erp-list aria-label="<?= $this->e($currentPage['name']) ?>">
    <?php $this->insert('components/filters/filter-bar', ['filterKeys' => $spec['filters'], 'filterStatus' => $statusColumn, 'filterTarget' => $tableId]); ?>
    <div class="erp-card erp-card--flush" style="margin-top:16px">
        <div class="erp-card__head"><div><h2><?= $this->e($currentPage['name']) ?></h2><p><?= $rows !== [] ? count($rows) . ' registros de demonstração' : 'Sem registros' ?></p></div></div>
        <?php $this->insert('components/tables/data-table', ['tableId' => $tableId, 'tableCaption' => $currentPage['name'], 'tableColumns' => $columns, 'tableRows' => $rows, 'tableActions' => true, 'tableEntity' => $entity, 'tableTotals' => false]); ?>
    </div>
</section>

<?php if ($canCreate) { $this->insert('components/modals/form-modal', ['modalId' => $tableId . '-form', 'modalTitle' => ucfirst($article . ' ' . $entity), 'modalFields' => $fields]); } ?>
<?php $this->insert('components/modals/confirm', ['modalId' => $tableId . '-delete', 'modalTitle' => 'Excluir ' . $entity, 'modalText' => ' A exclusão de ' . $entity . ' só será possível com o backend do módulo e com registro na auditoria.']); ?>

<dialog class="erp-modal erp-modal--wide" id="<?= $tableId ?>-detail" aria-labelledby="<?= $tableId ?>-detail-title">
    <div class="erp-modal__head">
        <div><span class="erp-eyebrow">Detalhe · demonstração</span><h2 id="<?= $tableId ?>-detail-title"><?= $this->e(ucfirst($entity)) ?></h2></div>
        <button type="button" class="erp-icon-button" data-erp-modal-close aria-label="Fechar"><?= $icon('close') ?></button>
    </div>
    <div class="erp-modal__body">
        <div class="erp-tabs" role="tablist" aria-label="Seções do detalhe">
            <?php foreach ($spec['tabs'] as $i => $tab): ?>
                <button type="button" role="tab" id="<?= $tableId ?>-tab-<?= $i ?>" aria-controls="<?= $tableId ?>-panel-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i ? ' tabindex="-1"' : '' ?>><?= $this->e($tab) ?></button>
            <?php endforeach; ?>
        </div>
        <?php foreach ($spec['tabs'] as $i => $tab): ?>
            <div class="erp-tabpanel" role="tabpanel" id="<?= $tableId ?>-panel-<?= $i ?>" aria-labelledby="<?= $tableId ?>-tab-<?= $i ?>"<?= $i ? ' hidden' : '' ?>>
                <?php if ($i === 0 && $rows !== []): ?>
                    <dl class="erp-detail-grid">
                        <?php foreach ($columns as $c => $column): ?><div><dt><?= $this->e($column['label']) ?></dt><dd><?= $this->e($rows[0][$c][0]) ?></dd></div><?php endforeach; ?>
                    </dl>
                <?php elseif ($tab === 'Histórico'): ?>
                    <ul class="erp-list">
                        <li><span class="erp-list__icon"><?= $icon('edit', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Cadastro atualizado</strong><small>Exemplo de registro de auditoria</small></div><span class="erp-list__meta">05/11/2026</span></li>
                        <li><span class="erp-list__icon"><?= $icon('plus', 'erp-icon--sm') ?></span><div class="erp-list__body"><strong>Cadastro criado</strong><small>Exemplo de registro de auditoria</small></div><span class="erp-list__meta">12/03/2026</span></li>
                    </ul>
                <?php else: ?>
                    <?php $this->insert('components/feedback/empty', ['emptyIcon' => 'clipboard', 'emptyTitle' => $tab, 'emptyText' => 'Esta seção mostrará ' . mb_strtolower($tab) . ' de ' . $entity . ' quando o módulo estiver integrado.']); ?>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="erp-modal__foot">
        <button type="button" class="erp-btn" data-erp-modal-close>Fechar</button>
        <?php if ($canCreate): ?><button type="button" class="erp-btn erp-btn--primary" data-erp-pending="Edição pendente: o backend deste módulo ainda não foi implementado.">Editar</button><?php endif; ?>
    </div>
</dialog>
