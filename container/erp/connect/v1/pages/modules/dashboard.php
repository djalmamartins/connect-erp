<?php
/** Painel de contexto (condomínio ou unidade). @var array $currentPage @var array $chart @var array $rows @var list<array> $columns */
$this->layout('default');
$isUnit = $currentPageId === 'AP-003';
$this->insert('components/layout/page-head', [
    'pageEyebrow' => 'Condomínios',
    'pageTitle' => $currentPage['name'],
    'pageLead' => $isUnit ? 'Situação de uma unidade: pessoas, débitos e histórico.' : 'Situação de um condomínio: arrecadação, despesas e pendências.',
]);
$this->insert('components/notifications/proposal');
$kpis = $isUnit
    ? [['Débitos em aberto', 'R$ 1.284,60', '2 boletos', 'alert', 'danger'], ['Último pagamento', '10/10/2026', 'R$ 642,30', 'check', ''], ['Pessoas vinculadas', '3', 'Proprietário e 2 moradores', 'users', 'info'], ['Ocorrências', '1', 'Em andamento', 'clipboard', 'warning']]
    : [['Unidades', '96', '4 vagas', 'building', ''], ['Arrecadação do mês', 'R$ 61.920', '92,4% do previsto', 'wallet', ''], ['Inadimplência', '4,1%', 'R$ 2.540 em aberto', 'alert', 'danger'], ['Saldo em conta', 'R$ 189.783', 'Conta principal', 'scale', 'info']];
?>
<form class="erp-filters" onsubmit="return false" aria-label="Contexto">
    <div class="erp-field erp-field--grow"><label for="ctx-condo">Condomínio</label><select class="erp-select" id="ctx-condo"><?php foreach ($vocabulary['condos'] ?? [] as $condo): ?><option><?= $this->e($condo) ?></option><?php endforeach; ?></select></div>
    <?php if ($isUnit): ?><div class="erp-field"><label for="ctx-unit">Unidade</label><input class="erp-input" id="ctx-unit" value="304-B"></div><?php endif; ?>
</form>
<?php if ($demo): ?>
<section class="erp-grid erp-grid--kpi" aria-label="Indicadores">
    <?php foreach ($kpis as $i => [$label, $value, $meta, $ic, $tone]) { $this->insert('components/cards/kpi', ['kpi' => ['label' => $label, 'value' => $value, 'meta' => $meta, 'icon' => $ic, 'tone' => $tone, 'trend' => null], 'delay' => $i * 60]); } ?>
</section>
<div class="erp-grid erp-grid--main">
    <section class="erp-card"><div class="erp-card__head"><div><h2><?= $isUnit ? 'Pagamentos da unidade' : 'Receitas e despesas' ?></h2><p>Últimos 6 meses</p></div><span class="erp-demo-flag">Demonstração</span></div><?php if ($chart) { $this->insert('components/charts/bars', ['chart' => $chart, 'chartCaption' => $currentPage['name']]); } ?></section>
    <section class="erp-card erp-card--flush"><div class="erp-card__head"><div><h2><?= $isUnit ? 'Débitos e lançamentos' : 'Contas a pagar da semana' ?></h2></div></div><?php $this->insert('components/tables/data-table', ['tableId' => 'ctx-' . strtolower($currentPageId), 'tableCaption' => $currentPage['name'], 'tableColumns' => $columns, 'tableRows' => $rows, 'tableActions' => false]); ?></section>
</div>
<?php else: ?>
    <section class="erp-card"><?php $this->insert('components/feedback/empty', ['emptyIcon' => 'chart', 'emptyTitle' => 'Indicadores ainda indisponíveis', 'emptyText' => 'O painel mostrará os números reais quando os módulos financeiros forem integrados.']); ?></section>
<?php endif; ?>
