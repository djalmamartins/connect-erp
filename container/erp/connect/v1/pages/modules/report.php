<?php
/** Relatório parametrizado: parâmetros, indicadores, tabela com totais e exportação. @var array $currentPage @var list<array> $columns @var array $rows */
$this->layout('default');
$tableId = 'rpt-' . strtolower($currentPageId);
ob_start(); ?>
    <button type="button" class="erp-btn" data-erp-pending="Exportação pendente: o PDF/planilha repetirá os filtros aplicados quando os dados forem reais."><?= $icon('download', 'erp-icon--sm') ?>Exportar</button>
    <button type="button" class="erp-btn" onclick="window.print()"><?= $icon('printer', 'erp-icon--sm') ?>Imprimir</button>
<?php $actions = (string)ob_get_clean();
$this->insert('components/layout/page-head', [
    'pageEyebrow' => $catalog['groups'][$currentPage['group']]['label'] . ($currentPage['section'] ? ' · ' . $currentPage['section'] : ''),
    'pageTitle' => $currentPage['name'],
    'pageLead' => 'Escolha o condomínio e o período. Período e critério aparecem na impressão e na exportação.',
    'pageActions' => $actions,
]);
$this->insert('components/notifications/proposal');
$moneyIndex = null;
foreach ($columns as $i => $c) { if ($c['type'] === 'money') { $moneyIndex = $i; } }
$total = $moneyIndex !== null ? array_sum(array_map(static fn (array $r): int => (int)$r[$moneyIndex][1], $rows)) : 0;
?>
<section data-erp-list aria-label="<?= $this->e($currentPage['name']) ?>">
    <form class="erp-filters" onsubmit="return false" aria-label="Parâmetros do relatório">
        <div class="erp-field erp-field--grow"><label for="<?= $tableId ?>-condo">Condomínio</label><select class="erp-select" id="<?= $tableId ?>-condo" data-erp-select-filter><option value="">Todos</option><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select></div>
        <div class="erp-field"><label for="<?= $tableId ?>-from">De</label><input class="erp-input" type="date" id="<?= $tableId ?>-from" value="2026-10-01"></div>
        <div class="erp-field"><label for="<?= $tableId ?>-to">Até</label><input class="erp-input" type="date" id="<?= $tableId ?>-to" value="2026-10-31"></div>
        <div class="erp-field"><label for="<?= $tableId ?>-crit">Critério</label><select class="erp-select" id="<?= $tableId ?>-crit"><option>Competência</option><option>Caixa</option></select></div>
        <div class="erp-field erp-field--grow"><label for="<?= $tableId ?>-q">Pesquisar</label><input class="erp-input" type="search" id="<?= $tableId ?>-q" data-erp-table-filter placeholder="Filtrar linhas"></div>
    </form>
    <?php if ($rows !== []): ?>
        <div class="erp-grid erp-grid--3" style="margin-top:16px">
            <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Registros no período', 'value' => (string)count($rows), 'meta' => 'Demonstração', 'icon' => 'file', 'tone' => '', 'trend' => null]]); ?>
            <?php if ($moneyIndex !== null): ?><?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Total do período', 'value' => 'R$ ' . number_format($total / 100, 2, ',', '.'), 'meta' => $columns[$moneyIndex]['label'], 'icon' => 'wallet', 'tone' => '', 'trend' => null], 'delay' => 60]); ?><?php endif; ?>
            <?php $this->insert('components/cards/kpi', ['kpi' => ['label' => 'Emitido em', 'value' => date('d/m/Y'), 'meta' => 'Critério: competência', 'icon' => 'calendar', 'tone' => 'info', 'trend' => null], 'delay' => 120]); ?>
        </div>
    <?php endif; ?>
    <div class="erp-card erp-card--flush" style="margin-top:16px">
        <div class="erp-card__head"><div><h2><?= $this->e($currentPage['name']) ?></h2><p>Período de 01/10/2026 a 31/10/2026</p></div></div>
        <?php $this->insert('components/tables/data-table', ['tableId' => $tableId, 'tableCaption' => $currentPage['name'], 'tableColumns' => $columns, 'tableRows' => $rows, 'tableActions' => false, 'tableTotals' => true]); ?>
    </div>
</section>
